<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\PaymentTokens;

/**
 * SyncEngine's copy of QuickBooks payments onto Fleetbase invoices: the amount paid, the
 * payment status and the payments a webhook reports.
 */
trait AppliesRemotePayments
{
    /**
     * @param array<string, mixed>                $connection
     * @param array<string, mixed>                $link
     * @param array<string, mixed>                $remote
     * @param array<string, array<string, mixed>> $remotes
     */
    private function applyPaymentRemote(
        SyncLedger $ledger,
        array $connection,
        string $companyUuid,
        array $link,
        array $remote,
        array $remotes,
    ): bool {
        $localUuid   = (string) ($link['local_uuid'] ?? '');
        $invoiceUuid = isset($ledger->invoices[$localUuid]) === true
            ? $localUuid
            : $this->invoiceUuidForPayment($ledger, $connection, $remote);
        if ($invoiceUuid === null) {
            return false;
        }
        $invoiceLink = $ledger->link($companyUuid, (string) $connection['realm_id'], 'invoice', $invoiceUuid);
        $invoiceId   = is_array($invoiceLink) === true ? trim((string) ($invoiceLink['qbo_id'] ?? '')) : '';
        $payments    = $this->paymentsTouchingInvoice(
            $ledger,
            $connection,
            $invoiceUuid,
            $invoiceId,
            $remote,
            $remotes
        );
        $this->applyPaymentsFromRemote($ledger, $invoiceUuid, $payments, $invoiceId);
        $this->applyPaymentStatus($ledger, $invoiceUuid);
        // Only this invoice took the payment: another invoice it pays still sees the change.
        if ((string) ($link['qbo_id'] ?? '') === (string) ($remote['Id'] ?? '')) {
            $token  = (string) ($remote['SyncToken'] ?? '');
            $tokens = PaymentTokens::with($link, $invoiceUuid, $token);
            $ledger->putLink(array_merge($link, [PaymentTokens::COLUMN => $tokens]));
        }

        return true;
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $remote
     */
    private function invoiceUuidForPayment(SyncLedger $ledger, array $connection, array $remote): ?string
    {
        $company = (string) $connection['company_uuid'];
        $realm   = (string) $connection['realm_id'];
        foreach ($ledger->invoices as $uuid => $invoice) {
            if (is_array($invoice) === false) {
                continue;
            }
            $link      = $ledger->link($company, $realm, 'invoice', (string) $uuid);
            $invoiceId = is_array($link) === true ? trim((string) ($link['qbo_id'] ?? '')) : '';
            if ($invoiceId !== '' && $this->linkedLineCents($remote, $invoiceId) !== null) {
                return (string) $uuid;
            }
        }

        return null;
    }

    /**
     * The changed payment plus every other payment already linked to this invoice.
     * Amounts stay in integer minor units.
     *
     * @param array<string, mixed>                $connection
     * @param array<string, mixed>                $changed
     * @param array<string, array<string, mixed>> $alreadyRead
     *
     * @return array<int, array<string, mixed>>
     */
    private function paymentsTouchingInvoice(
        SyncLedger $ledger,
        array $connection,
        string $invoiceUuid,
        string $invoiceId,
        array $changed,
        array $alreadyRead,
    ): array {
        $payments = $this->seedPaymentsTouchingInvoice($changed, $alreadyRead, $invoiceId);
        $missing  = $this->missingPaymentLinks($ledger, $connection, $invoiceUuid, $payments);
        foreach ($this->readRemoteIds($connection, 'Payment', array_keys($missing)) as $remoteId => $remote) {
            $local = $missing[$remoteId] ?? '';
            if (
                $local === $invoiceUuid
                || ($invoiceId !== '' && $this->linkedLineCents($remote, $invoiceId) !== null)
            ) {
                $payments[$remoteId] = $remote;
            }
        }

        return array_values($payments);
    }

    /**
     * @param array<string, mixed>                $changed
     * @param array<string, array<string, mixed>> $alreadyRead
     *
     * @return array<string, array<string, mixed>>
     */
    private function seedPaymentsTouchingInvoice(array $changed, array $alreadyRead, string $invoiceId): array
    {
        $payments  = [];
        $changedId = trim((string) ($changed['Id'] ?? ''));
        if ($changedId !== '') {
            $payments[$changedId] = $changed;
        }
        foreach ($alreadyRead as $remote) {
            if (is_array($remote) === false) {
                continue;
            }
            $remoteId = trim((string) ($remote['Id'] ?? ''));
            if ($remoteId === '' || isset($payments[$remoteId]) === true) {
                continue;
            }
            if ($invoiceId !== '' && $this->linkedLineCents($remote, $invoiceId) !== null) {
                $payments[$remoteId] = $remote;
            }
        }

        return $payments;
    }

    /**
     * @param array<int, array<string, mixed>> $payments
     */
    private function applyPaymentsFromRemote(SyncLedger $ledger, string $uuid, array $payments, string $invoiceId): void
    {
        $latest = '';
        foreach ($payments as $remote) {
            $date = substr(trim((string) ($remote['TxnDate'] ?? '')), 0, 10);
            if ($date !== '' && ($latest === '' || $date > $latest)) {
                $latest = $date;
            }
        }
        if ($latest !== '') {
            $ledger->invoices[$uuid]['paid_at'] = $latest;
        }
        $amount = $this->summedOnInvoice($payments, $invoiceId, (string) ($ledger->invoices[$uuid]['currency'] ?? ''));
        if ($amount !== null) {
            $ledger->invoices[$uuid]['amount_paid'] = $amount;
        }
        $ledger->invoices[$uuid]['payment_from_quickbooks'] = true;
    }

    /**
     * A Fleetbase update sends one Invoice line and this invoice's amount as TotalAmt, and
     * QuickBooks replaces the whole Line array. Credit memo lines, other invoices and an
     * unapplied amount (customer credit) would be lost, so such a payment is left alone.
     *
     * @param array<string, mixed> $remote
     */
    private function paymentAppliesElsewhere(array $remote, string $invoiceId, string $currency = ''): bool
    {
        if ($this->paymentCoversOtherInvoices($remote, $invoiceId) === true) {
            return true;
        }
        $lines = is_array($remote['Line'] ?? null) === true ? $remote['Line'] : [];
        foreach ($lines as $line) {
            $txns = is_array($line) === true && is_array($line['LinkedTxn'] ?? null) === true ? $line['LinkedTxn'] : [];
            foreach ($txns as $txn) {
                $type = is_array($txn) === true ? (string) ($txn['TxnType'] ?? '') : '';
                if ($type !== '' && $type !== 'Invoice') {
                    return true;
                }
            }
        }
        $applied = $this->linkedLineCents($remote, $invoiceId, $currency);

        return $applied !== null
            && array_key_exists('TotalAmt', $remote) === true
            && $this->majorUnits($remote['TotalAmt'], $currency) > $applied;
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function paymentCoversOtherInvoices(array $remote, string $invoiceId): bool
    {
        $lines = $remote['Line'] ?? null;
        if (is_array($lines) === false) {
            return false;
        }

        foreach ($lines as $line) {
            if (is_array($line) === false) {
                continue;
            }
            $txns = $line['LinkedTxn'] ?? [];
            if (is_array($txns) === false) {
                continue;
            }
            foreach ($txns as $txn) {
                if (is_array($txn) === false) {
                    continue;
                }
                $type = (string) ($txn['TxnType'] ?? '');
                if ($type !== '' && $type !== 'Invoice') {
                    continue;
                }
                $txnId = (string) ($txn['TxnId'] ?? '');
                if ($txnId !== '' && $txnId !== $invoiceId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The QuickBooks payment flag only tells the directory to persist. It is not
     * stored, so setting it again is not a change the activity log should count.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function paymentRecordChanged(array $before, array $after): bool
    {
        foreach (['amount_paid', 'paid_at', 'status'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A QuickBooks invoice with nothing left owing is paid, even when a discount made the amount
     * paid smaller than the Fleetbase total.
     *
     * @param array<string, mixed>|null $remoteInvoice the QuickBooks invoice the payments came from
     */
    private function applyPaymentStatus(SyncLedger $ledger, string $uuid, ?array $remoteInvoice = null): void
    {
        $invoice = $ledger->invoices[$uuid];
        $status  = (string) ($invoice['status'] ?? '');
        if (
            in_array($status, ['void', 'voided', 'cancelled', 'canceled', 'deleted'], true) === true
            || empty($invoice['deleted_at']) === false
        ) {
            return;
        }

        $hasPaid  = array_key_exists('amount_paid', $invoice);
        $paid     = (int) ($invoice['amount_paid'] ?? 0);
        $total    = (int) ($invoice['total'] ?? 0);
        $currency = (string) ($invoice['currency'] ?? '');
        $settled  = $paid > 0 && $this->remoteSettled($remoteInvoice, $currency) === true;
        if (($total > 0 && $paid >= $total) || $settled === true) {
            $ledger->invoices[$uuid]['status'] = 'paid';

            return;
        }
        if ($paid > 0 && $paid < $total) {
            $ledger->invoices[$uuid]['status'] = 'partial';
            $this->clearQuickBooksPaidAt($ledger, $uuid);

            return;
        }
        if ($hasPaid === true && $paid === 0 && $total > 0) {
            $status = (string) ($invoice['status'] ?? '');
            if ($status === '' || in_array($status, ['paid', 'partial'], true) === true) {
                $ledger->invoices[$uuid]['status'] = 'sent';
                $this->clearQuickBooksPaidAt($ledger, $uuid);
            }
        }
    }
}
