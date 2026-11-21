<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\Translation;

/**
 * SyncEngine's payment sync: sending Fleetbase payments to QuickBooks, reading QuickBooks payments
 * back and keeping payment links. It shares the engine's state, so it lives here only to keep
 * SyncEngine.php readable.
 *
 * @phpstan-import-type AppliedPayments from SyncEngine
 * @phpstan-import-type PaymentPlan from SyncEngine
 */
trait SyncsPayments
{
    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $settings
     */
    private function syncPayment(
        SyncLedger $ledger,
        array $connection,
        array $invoice,
        string $customerRef,
        string $invoiceId,
        array $settings,
    ): ?string {
        if ($invoiceId === '') {
            return null;
        }
        $plan = $this->paymentPlan($settings, $invoice);
        if ($plan === null) {
            return null;
        }

        $applied = $this->paymentsApplied($ledger, $connection, (string) $invoice['uuid'], $invoiceId);
        $args    = [$ledger, $connection, $invoice, $customerRef, $invoiceId, $settings, $plan, $applied];

        return $applied['payments'] === [] ? $this->syncUnlinkedPayment(...$args) : $this->syncLinkedPayments(...$args);
    }

    /**
     * How this invoice's payment syncs, or null when it does not.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $invoice
     *
     * @return PaymentPlan|null
     */
    private function paymentPlan(array $settings, array $invoice): ?array
    {
        $payDirection = $this->direction($settings, 'payment');
        if ($this->entityPaused($settings, 'payment') === true) {
            return null;
        }
        $copyPay = $this->copiesRemote($payDirection);

        $reference         = (string) ($settings['payment_reference'] ?? 'fleetbase');
        $conflict          = (string) ($settings['payment_conflict'] ?? 'fleetbase');
        $quickbooksPrimary = ($reference === 'quickbooks' || $conflict === 'quickbooks') && $copyPay === true;
        $pushesPayment     = in_array((string) ($invoice['status'] ?? ''), ['paid', 'partial'], true);
        if ($pushesPayment === false && $quickbooksPrimary === false) {
            return null;
        }

        return [
            'copy'     => $copyPay,
            'push'     => $this->pushesRemote($payDirection),
            'conflict' => $conflict,
            'primary'  => $quickbooksPrimary,
            'pushes'   => $pushesPayment,
        ];
    }

    /**
     * QuickBooks shows no payment for the invoice: create the Fleetbase payment there, or take
     * the amount paid from the QuickBooks balance.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $settings
     * @param PaymentPlan          $plan
     * @param AppliedPayments      $applied
     */
    private function syncUnlinkedPayment(
        SyncLedger $ledger,
        array $connection,
        array $invoice,
        string $customerRef,
        string $invoiceId,
        array $settings,
        array $plan,
        array $applied,
    ): ?string {
        $uuid          = (string) $invoice['uuid'];
        $remoteInvoice = $applied['invoice'];
        // QuickBooks keeps the payments and shows none for this unpaid invoice, so an amount
        // Fleetbase still holds (a payment deleted in QuickBooks) comes from the balance.
        $unbacked = $plan['primary'] === true
            && $plan['pushes'] === false
            && $applied['missing'] === false
            && (int) ($invoice['amount_paid'] ?? 0) !== 0;
        if ($unbacked === true && is_array($remoteInvoice) === true) {
            return $this->applyAmountPaidFromBalance($ledger, $uuid, $remoteInvoice) === true ? 'updated' : null;
        }
        $sends = $applied['missing'] === false && $applied['settled'] === false
            && $plan['pushes'] === true && $plan['push'] === true;
        if ($sends === true) {
            $this->createRemotePayment(
                $ledger,
                $connection,
                $invoice,
                $customerRef,
                $invoiceId,
                $settings,
                $remoteInvoice
            );
        }

        return null;
    }

    /**
     * Send the Fleetbase payment to QuickBooks as a new payment.
     *
     * @param array<string, mixed>      $connection
     * @param array<string, mixed>      $invoice
     * @param array<string, mixed>      $settings
     * @param array<string, mixed>|null $remoteInvoice
     */
    private function createRemotePayment(
        SyncLedger $ledger,
        array $connection,
        array $invoice,
        string $customerRef,
        string $invoiceId,
        array $settings,
        ?array $remoteInvoice,
    ): void {
        $uuid        = (string) $invoice['uuid'];
        $amountCents = $this->paymentAmountCents($invoice, $remoteInvoice);
        if ($amountCents <= 0) {
            return;
        }
        $this->noteCappedPayment($invoice, $amountCents);
        $payload = $this->invoiceMapper($invoice)->payment(
            $customerRef,
            $invoiceId,
            $amountCents,
            $this->paymentDate($invoice),
            (string) ($invoice['payment_reference'] ?? ''),
            (string) ($invoice['payment_method'] ?? '')
        );
        if ($this->writeBuffer !== null) {
            $this->writeBuffer[] = [
                'bId'       => 'payment:' . $uuid,
                'operation' => 'create',
                'entity'    => 'Payment',
                'payload'   => $payload,
            ];

            return;
        }
        $created = $this->client->createPayment($connection, $payload);
        $this->storePaymentLink($ledger, $connection, $uuid, $invoiceId, $created, $remoteInvoice);
        $this->rememberInvoicesAfterPayments($ledger, $connection, [$uuid => $invoiceId], $settings);
    }

    /**
     * QuickBooks shows payments for the invoice: copy them to Fleetbase or send the Fleetbase
     * payment, by the direction and conflict rule.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $settings
     * @param PaymentPlan          $plan
     * @param AppliedPayments      $applied
     */
    private function syncLinkedPayments(
        SyncLedger $ledger,
        array $connection,
        array $invoice,
        string $customerRef,
        string $invoiceId,
        array $settings,
        array $plan,
        array $applied,
    ): ?string {
        $uuid          = (string) $invoice['uuid'];
        $payments      = $applied['payments'];
        $remoteInvoice = $applied['invoice'];
        // Read before the links below store the tokens QuickBooks returns now.
        $paymentChanged = $this->paymentsChangedInQuickBooks($ledger, $connection, $uuid, $payments);
        foreach ($payments as $remote) {
            $this->storePaymentLink($ledger, $connection, $uuid, $invoiceId, $remote, $remoteInvoice);
        }

        if ($plan['pushes'] === false) {
            $copied = $this->copyRemotePayments($ledger, $uuid, $payments, $invoiceId, $remoteInvoice);

            return $copied === true ? 'updated' : null;
        }

        if ($this->paymentsMatch($invoice, $payments, $invoiceId, $remoteInvoice) === true) {
            $copies = $plan['primary'] === true || ($plan['push'] === false && $plan['copy'] === true);

            return $copies === true ? $this->markSettledPaid($ledger, $uuid, $remoteInvoice) : null;
        }

        if ($plan['conflict'] === 'quickbooks' && $plan['copy'] === true && $paymentChanged === true) {
            $changed = $this->copyRemotePayments($ledger, $uuid, $payments, $invoiceId, $remoteInvoice);
            $this->notePrimaryCopy('invoice', $uuid, $plan['push'], $changed);

            return $changed === true ? 'updated' : null;
        }

        if ($plan['push'] === false) {
            $copied = $plan['copy'] === true
                && $this->copyRemotePayments($ledger, $uuid, $payments, $invoiceId, $remoteInvoice) === true;

            return $copied === true ? 'updated' : null;
        }

        return $this->updateRemotePayment(
            $ledger,
            $connection,
            $invoice,
            $customerRef,
            $invoiceId,
            $settings,
            $payments,
            $remoteInvoice
        );
    }

    /**
     * Copy QuickBooks payments onto the Fleetbase invoice. True when its payment record changed.
     *
     * @param array<int, array<string, mixed>> $payments
     * @param array<string, mixed>|null        $remoteInvoice
     */
    private function copyRemotePayments(
        SyncLedger $ledger,
        string $uuid,
        array $payments,
        string $invoiceId,
        ?array $remoteInvoice,
    ): bool {
        $before = $ledger->invoices[$uuid];
        $this->applyPaymentsFromRemote($ledger, $uuid, $payments, $invoiceId);
        $this->applyPaymentStatus($ledger, $uuid, $remoteInvoice);

        return $this->paymentRecordChanged($before, $ledger->invoices[$uuid]);
    }

    /**
     * Send the Fleetbase amount to the one QuickBooks payment applied to this invoice.
     *
     * @param array<string, mixed>             $connection
     * @param array<string, mixed>             $invoice
     * @param array<string, mixed>             $settings
     * @param array<int, array<string, mixed>> $payments
     * @param array<string, mixed>|null        $remoteInvoice
     */
    private function updateRemotePayment(
        SyncLedger $ledger,
        array $connection,
        array $invoice,
        string $customerRef,
        string $invoiceId,
        array $settings,
        array $payments,
        ?array $remoteInvoice,
    ): ?string {
        if (count($payments) !== 1) {
            $this->lastError = 'quickbooks.messages.invoice-payments-multiple';

            return 'skipped';
        }

        $uuid   = (string) $invoice['uuid'];
        $remote = $payments[0];
        if ($this->paymentAppliesElsewhere($remote, $invoiceId, (string) ($invoice['currency'] ?? '')) === true) {
            $this->lastError = 'quickbooks.messages.payment-shared';

            return 'skipped';
        }

        $amountCents = $this->paymentAmountCents($invoice, $remoteInvoice);
        if ($amountCents <= 0) {
            return null;
        }
        $this->noteCappedPayment($invoice, $amountCents);
        $payload = $this->invoiceMapper($invoice)
            ->payment($customerRef, $invoiceId, $amountCents, $this->paymentDate($invoice));
        if ($this->writeBuffer !== null) {
            $payload['Id']        = (string) $remote['Id'];
            $payload['SyncToken'] = (string) $remote['SyncToken'];
            $payload['sparse']    = true;
            $this->writeBuffer[]  = [
                'bId'       => 'payment:' . $uuid,
                'operation' => 'update',
                'entity'    => 'Payment',
                'payload'   => $payload,
            ];

            return 'updated';
        }

        $updated = $this->client->updatePayment(
            $connection,
            (string) $remote['Id'],
            (string) $remote['SyncToken'],
            $payload
        );
        $this->storePaymentLink($ledger, $connection, $uuid, $invoiceId, $updated, $remoteInvoice);
        $this->rememberInvoicesAfterPayments($ledger, $connection, [$uuid => $invoiceId], $settings);

        return 'updated';
    }

    /**
     * Every payment applied to this invoice. A failed block read is not treated as
     * "no payment", and it is not fetched again one id at a time.
     *
     * @param array<string, mixed> $connection
     *
     * @return AppliedPayments
     */
    private function paymentsApplied(
        SyncLedger $ledger,
        array $connection,
        string $invoiceUuid,
        string $invoiceId,
    ): array {
        $remoteInvoice  = $this->remoteInvoice($connection, $invoiceId);
        $remoteInvoice  = is_array($remoteInvoice) === true ? $remoteInvoice : null;
        [$ids, $listed] = $this->appliedPaymentIds($ledger, $connection, $invoiceUuid, $invoiceId, $remoteInvoice);
        foreach (array_keys($ids) as $paymentId) {
            if (isset($this->paymentReadFailed[$paymentId]) === true) {
                throw $this->paymentReadFailed[$paymentId];
            }
        }

        [$payments, $missing] = $this->readAppliedPayments($connection, $ids, $listed, $invoiceId);
        $payments += $this->paymentsStoredById($ledger, $connection, $invoiceId, $payments, $ids);

        return [
            'payments' => array_values($payments),
            'settled'  => $payments !== [] || $this->remoteInvoiceSettled($ledger, $invoiceUuid, $remoteInvoice),
            'invoice'  => $remoteInvoice,
            'missing'  => $missing === true && $payments === [],
        ];
    }

    /**
     * Payment ids applied to this invoice, and the ones QuickBooks or a link lists for it.
     *
     * @param array<string, mixed>      $connection
     * @param array<string, mixed>|null $remoteInvoice
     *
     * @return array{0: array<string, true>, 1: array<string, true>}
     */
    private function appliedPaymentIds(
        SyncLedger $ledger,
        array $connection,
        string $invoiceUuid,
        string $invoiceId,
        ?array $remoteInvoice,
    ): array {
        $listed = [];
        foreach ($remoteInvoice === null ? [] : $this->linkedPaymentIds($remoteInvoice) as $paymentId) {
            $listed[$paymentId] = true;
        }
        foreach ($this->blockPayments[$invoiceId] ?? [] as $payment) {
            $paymentId = trim((string) ($payment['Id'] ?? ''));
            if ($paymentId !== '') {
                $listed[$paymentId] = true;
            }
        }
        $legacy    = $ledger->link(
            (string) $connection['company_uuid'],
            (string) $connection['realm_id'],
            'payment',
            $invoiceUuid
        );
        $paymentId = is_array($legacy) === true ? trim((string) ($legacy['qbo_id'] ?? '')) : '';
        if ($paymentId !== '') {
            $listed[$paymentId] = true;
        }
        // A payment stored under its QuickBooks id is absent from the invoice body after
        // that link moves. The payment-invoice row is the local record that it still applies.
        $ids = $listed;
        foreach (array_keys($this->rememberedPaymentIds($ledger, $connection, [$invoiceUuid])) as $paymentId) {
            $ids[$paymentId] = true;
        }

        return [$ids, $listed];
    }

    /**
     * Read each applied payment. A payment that is gone, or that only a local record says
     * applies and no longer does, makes the result "missing".
     *
     * @param array<string, mixed> $connection
     * @param array<string, true>  $ids
     * @param array<string, true>  $listed
     *
     * @return array{0: array<string, array<string, mixed>>, 1: bool}
     */
    private function readAppliedPayments(array $connection, array $ids, array $listed, string $invoiceId): array
    {
        $payments = [];
        $missing  = false;
        foreach (array_keys($ids) as $paymentId) {
            $remote = $this->readPayment($connection, $paymentId);
            if (is_array($remote) === false) {
                $missing = true;
                continue;
            }
            if (isset($listed[$paymentId]) === false && $this->linkedLineCents($remote, $invoiceId) === null) {
                $missing = true;
                continue;
            }
            $payments[$paymentId] = $remote;
        }

        return [$payments, $missing];
    }

    /**
     * Payments stored under their own QuickBooks id that apply to this invoice.
     *
     * @param array<string, mixed>                $connection
     * @param array<string, array<string, mixed>> $payments
     * @param array<string, true>                 $ids
     *
     * @return array<string, array<string, mixed>>
     */
    private function paymentsStoredById(
        SyncLedger $ledger,
        array $connection,
        string $invoiceId,
        array $payments,
        array $ids,
    ): array {
        $found = [];
        foreach ($this->paymentLinkRows($ledger, $connection) as $link) {
            $paymentId = trim((string) ($link['qbo_id'] ?? ''));
            $skip      = $paymentId === ''
                || (string) ($link['local_uuid'] ?? '') !== $paymentId
                || isset($payments[$paymentId]) === true
                || isset($ids[$paymentId]) === true
                || isset($found[$paymentId]) === true
                || isset($this->paymentReadFailed[$paymentId]) === true;
            if ($skip === true) {
                continue;
            }
            $remote = $this->readPayment($connection, $paymentId);
            if (is_array($remote) === true && $this->linkedLineCents($remote, $invoiceId) !== null) {
                $found[$paymentId] = $remote;
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed>|null $remoteInvoice
     */
    private function remoteInvoiceSettled(SyncLedger $ledger, string $invoiceUuid, ?array $remoteInvoice): bool
    {
        $balance  = $remoteInvoice['Balance'] ?? null;
        $currency = (string) ($ledger->invoices[$invoiceUuid]['currency'] ?? '');

        return $balance !== null && $balance !== '' && $this->majorUnits($balance, $currency) === 0;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    private function readPayment(array $connection, string $paymentId): ?array
    {
        if (isset($this->paymentReadFailed[$paymentId]) === true) {
            throw $this->paymentReadFailed[$paymentId];
        }
        if ($this->paymentById !== null) {
            if (array_key_exists($paymentId, $this->paymentById) === false) {
                return null;
            }
            $cached = $this->paymentById[$paymentId];

            return is_array($cached) === true ? $cached : null;
        }

        return $this->client->getPayment($connection, $paymentId);
    }

    /**
     * The QuickBooks payment date: the paid date, else the day Ledger recorded the last part
     * payment, else the invoice date so a new payment still has a date.
     *
     * @param array<string, mixed> $invoice
     */
    private function paymentDate(array $invoice): string
    {
        $recorded = $this->recordedPaymentDate($invoice);
        if ($recorded !== '') {
            return $recorded;
        }

        return trim((string) ($invoice['date'] ?? ''));
    }

    /**
     * A date Fleetbase actually recorded for the payment. The invoice-date stand-in is not one,
     * so it never makes Fleetbase rewrite the date of an existing QuickBooks payment.
     *
     * @param array<string, mixed> $invoice
     */
    private function recordedPaymentDate(array $invoice): string
    {
        foreach (['paid_at', 'payment_date'] as $key) {
            $value = trim((string) ($invoice[$key] ?? ''));
            if ($value !== '') {
                return substr($value, 0, 10);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed>      $invoice
     * @param array<string, mixed>      $remote
     * @param array<string, mixed>|null $remoteInvoice
     */
    private function paymentMatches(array $invoice, array $remote, string $invoiceId, ?array $remoteInvoice): bool
    {
        $remoteCents = $this->amountOnInvoice($remote, $invoiceId, (string) ($invoice['currency'] ?? ''));
        if ($remoteCents === null || $remoteCents !== $this->paymentAmountCents($invoice, $remoteInvoice)) {
            return false;
        }

        $localDate  = $this->recordedPaymentDate($invoice);
        $remoteDate = substr(trim((string) ($remote['TxnDate'] ?? '')), 0, 10);
        if ($localDate !== '' && $remoteDate !== '' && $localDate !== $remoteDate) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed>             $invoice
     * @param array<int, array<string, mixed>> $payments
     * @param array<string, mixed>|null        $remoteInvoice
     */
    private function paymentsMatch(array $invoice, array $payments, string $invoiceId, ?array $remoteInvoice): bool
    {
        if (count($payments) === 1) {
            return $this->paymentMatches($invoice, $payments[0], $invoiceId, $remoteInvoice);
        }
        $remoteCents = $this->summedOnInvoice($payments, $invoiceId, (string) ($invoice['currency'] ?? ''));

        return $remoteCents !== null && $remoteCents === $this->paymentAmountCents($invoice, $remoteInvoice);
    }

    /**
     * Compare the amount written onto the invoice. Invoice total is the stand-in
     * before a payment amount has been recorded. A paid invoice stored with
     * amount paid 0 uses that total, so the payment is not sent as zero. The
     * amount is capped at the QuickBooks invoice total ($remoteInvoice).
     *
     * @param array<string, mixed>      $invoice
     * @param array<string, mixed>|null $remoteInvoice
     */
    private function paymentAmountCents(array $invoice, ?array $remoteInvoice = null): int
    {
        if (empty($invoice['payment_from_quickbooks']) === false) {
            return (int) ($invoice['amount_paid'] ?? 0);
        }
        $amount = $this->recordedPaymentCents($invoice);
        $total  = is_array($remoteInvoice) === true && array_key_exists('TotalAmt', $remoteInvoice) === true
            ? $this->majorUnits($remoteInvoice['TotalAmt'], (string) ($invoice['currency'] ?? ''))
            : null;

        // QuickBooks applies at most the invoice total and keeps the rest of a payment unapplied.
        return $total !== null && $total > 0 && $amount > $total ? $total : $amount;
    }

    /**
     * Says on the invoice's Activity row when its payment goes to QuickBooks capped at the
     * QuickBooks invoice total.
     *
     * @param array<string, mixed> $invoice
     */
    private function noteCappedPayment(array $invoice, int $amountCents): void
    {
        $fromQuickBooks = empty($invoice['payment_from_quickbooks']) === false;
        if ($fromQuickBooks === false && $amountCents < $this->recordedPaymentCents($invoice)) {
            $this->rowNotes['invoice:' . (string) $invoice['uuid']] ??= Translation::encode(
                'quickbooks.messages.payment-capped'
            );
        }
    }

    /**
     * The amount Fleetbase holds as paid on an invoice, before any cap.
     *
     * @param array<string, mixed> $invoice
     */
    private function recordedPaymentCents(array $invoice): int
    {
        $recorded = array_key_exists('amount_paid', $invoice) === true;
        $paid     = (int) ($invoice['amount_paid'] ?? 0);
        if ($recorded === true && $paid === 0 && (string) ($invoice['status'] ?? '') === 'paid') {
            return (int) ($invoice['total'] ?? 0);
        }
        if ($recorded === true) {
            return $paid;
        }

        return (int) ($invoice['total'] ?? 0);
    }

    private function withPayment(string $outcome, ?string $paymentOutcome): string
    {
        if ($paymentOutcome === 'skipped' && $outcome === 'aligned') {
            return 'skipped';
        }
        if ($paymentOutcome === 'updated' && ($outcome === 'aligned' || $outcome === 'created')) {
            return 'updated';
        }

        return $outcome;
    }
}
