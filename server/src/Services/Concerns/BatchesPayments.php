<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\QuickBooksException;

/**
 * SyncEngine's payment work in block mode: reading every payment a block needs up front,
 * and recording the payment writes it sends in one batch.
 */
trait BatchesPayments
{
    /**
     * @return array<string, true>
     */
    private function queuedPaymentUuids(): array
    {
        $uuids = [];
        foreach ($this->writeBuffer ?? [] as $op) {
            $bId = $op['bId'];
            if (str_starts_with($bId, 'payment:') === true) {
                $uuids[substr($bId, strlen('payment:'))] = true;
            }
        }

        return $uuids;
    }

    /**
     * Record payment writes, then record each invoice. A failed payment is that invoice's result.
     * A 401 stops the block and leaves this invoice and every invoice after it pending.
     *
     * @param array<string, array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool, planError: string|null}> $finished
     * @param array<string, mixed>                                                                                                          $batch
     * @param array<string, mixed>                                                                                                          $settings
     */
    private function settlePaymentBuffer(SyncLedger $ledger, array $connection, array $finished, array &$batch, array $settings, int $now, string $trigger, bool $record): bool
    {
        $queued = $this->queuedPaymentUuids();
        if ($queued === []) {
            $this->writeBuffer = null;
            $this->commitFinished($ledger, $connection, $finished, $batch, $trigger, $record);

            return false;
        }

        try {
            $paymentResults = $this->flushWrites($connection);
        } catch (\Throwable $exception) {
            $paymentResults = $exception;
        }
        $this->writeBuffer = null;
        $settled           = $paymentResults instanceof \Throwable
            ? $this->paymentFlushFailed($ledger, $connection, $finished, $queued, $paymentResults, $settings, $now)
            : $this->applyPaymentResults($ledger, $connection, $finished, $paymentResults, $settings, $now);

        $this->commitFinished($ledger, $connection, $settled['commit'], $batch, $trigger, $record);
        if ($settled['rejected'] === true) {
            $this->tokenRejected($ledger, (string) $connection['company_uuid']);
            $batch['failed']++;
        }

        return $settled['stop'];
    }

    /**
     * The payment batch failed as a whole. A 401 or rate limit stops the block.
     *
     * @param array<string, mixed>                                                                                                          $connection
     * @param array<string, array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool, planError: string|null}> $finished
     * @param array<string, true>                                                                                                           $queued
     * @param array<string, mixed>                                                                                                          $settings
     *
     * @return array{commit: array<string, array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool, planError: string|null}>, stop: bool, rejected: bool}
     */
    private function paymentFlushFailed(
        SyncLedger $ledger,
        array $connection,
        array $finished,
        array $queued,
        \Throwable $exception,
        array $settings,
        int $now,
    ): array {
        $quickBooks = $exception instanceof QuickBooksException;
        if ($quickBooks === true && $exception->isUnauthorized() === true) {
            return ['commit' => $this->finishedBeforePayment($finished, $queued), 'stop' => true, 'rejected' => true];
        }
        $error = $this->exceptionError($exception);
        if ($quickBooks === true && $exception->isRateLimit() === true) {
            $stop = $this->firstQueuedUuid($finished, $queued);
            if ($stop !== null && isset($finished[$stop]) === true) {
                $finished[$stop] = $this->failedPaymentItem($ledger, $connection, $finished[$stop], $exception, $settings, $now, $error);
            }

            return ['commit' => $this->finishedThrough($finished, $stop), 'stop' => true, 'rejected' => false];
        }
        foreach (array_keys($queued) as $uuid) {
            if (isset($finished[$uuid]) === true) {
                $finished[$uuid] = $this->failedPaymentItem($ledger, $connection, $finished[$uuid], $exception, $settings, $now, $error);
            }
        }

        return ['commit' => $finished, 'stop' => false, 'rejected' => false];
    }

    /**
     * Apply each payment result to its invoice. A halted result stops the block there.
     *
     * @param array<string, mixed>                                                                                                          $connection
     * @param array<string, array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool, planError: string|null}> $finished
     * @param array<string, mixed>                                                                                                          $paymentResults
     * @param array<string, mixed>                                                                                                          $settings
     *
     * @return array{commit: array<string, array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool, planError: string|null}>, stop: bool, rejected: bool}
     */
    private function applyPaymentResults(
        SyncLedger $ledger,
        array $connection,
        array $finished,
        array $paymentResults,
        array $settings,
        int $now,
    ): array {
        $paidInvoices = [];
        foreach ($finished as $uuid => $item) {
            $uuid   = (string) $uuid;
            $result = $paymentResults['payment:' . $uuid] ?? null;
            if (is_array($result) === false) {
                continue;
            }
            $message = (string) ($result['error'] ?? '');
            $status  = (int) ($result['status'] ?? 0);
            if (empty($result['halt']) === false) {
                if ($status === 401) {
                    return ['commit' => $this->finishedThrough($finished, $uuid, false), 'stop' => true, 'rejected' => true];
                }
                $exception       = new QuickBooksException($status, $message);
                $error           = $this->batchItemAttemptError($message, $status);
                $finished[$uuid] = $this->failedPaymentItem($ledger, $connection, $item, $exception, $settings, $now, $error);
                // The payments before this one were sent: their invoices keep the step they took.
                $this->rememberInvoicesAfterPayments($ledger, $connection, $paidInvoices, $settings);

                return ['commit' => $this->finishedThrough($finished, $uuid, true), 'stop' => true, 'rejected' => false];
            }
            if (empty($result['ok']) === true) {
                $exception       = new QuickBooksException(
                    (int) ($result['status'] ?? 400),
                    $message !== '' ? $message : self::ITEM_REJECTED_MESSAGE
                );
                $error           = $this->batchItemAttemptError($message, $status);
                $finished[$uuid] = $this->failedPaymentItem($ledger, $connection, $item, $exception, $settings, $now, $error);
                continue;
            }
            if (is_array($result['body'] ?? null) === true && ($result['body']['Id'] ?? '') !== '') {
                $paidInvoices[$uuid] = $this->storeSentPayment($ledger, $connection, $uuid, $result['body']);
            }
        }
        $this->rememberInvoicesAfterPayments($ledger, $connection, $paidInvoices, $settings);

        return ['commit' => $finished, 'stop' => false, 'rejected' => false];
    }

    /**
     * Link a payment QuickBooks accepted in a batch, and return its invoice's QuickBooks id.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payment
     */
    private function storeSentPayment(SyncLedger $ledger, array $connection, string $uuid, array $payment): string
    {
        $invoiceLink = $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'invoice', $uuid);
        $invoiceId   = is_array($invoiceLink) === true ? trim((string) ($invoiceLink['qbo_id'] ?? '')) : '';
        $this->storePaymentLink($ledger, $connection, $uuid, $invoiceId, $payment);

        return $invoiceId;
    }

    /**
     * A finished invoice whose payment write failed: its result is the payment's failure.
     *
     * @param array<string, mixed>                                                                                           $connection
     * @param array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool, planError: string|null} $item
     * @param array<string, mixed>                                                                                           $settings
     *
     * @return array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool, planError: string|null}
     */
    private function failedPaymentItem(
        SyncLedger $ledger,
        array $connection,
        array $item,
        \Throwable $exception,
        array $settings,
        int $now,
        string $error,
    ): array {
        $item['outcome']   = $this->failure($ledger, $connection, $item['row'], $exception, $settings, $now);
        $item['error']     = $error;
        $item['permanent'] = false;

        return $item;
    }

    /**
     * @param array<string, array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool, planError: string|null}> $finished
     * @param array<string, true>                                                                                                           $queued
     *
     * @return array<string, array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool, planError: string|null}>
     */
    private function finishedBeforePayment(array $finished, array $queued): array
    {
        $stop = $this->firstQueuedUuid($finished, $queued);

        return $stop === null ? [] : $this->finishedThrough($finished, $stop, false);
    }

    /**
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     */
    private function prefetchPayments(array $connection, SyncLedger $ledger, array $rows): void
    {
        $this->blockPayments      = [];
        $this->paymentPrefetchIds = [];
        $this->paymentById        = [];
        $this->paymentReadFailed  = [];
        $companyUuid              = (string) $connection['company_uuid'];
        $realm                    = (string) $connection['realm_id'];
        $invoiceIds               = [];
        foreach ($rows as $row) {
            $uuid    = (string) ($row['local_uuid'] ?? '');
            $invoice = $ledger->invoices[$uuid] ?? null;
            if (is_array($invoice) === false) {
                continue;
            }
            $customerUuid = (string) ($invoice['customer_uuid'] ?? '');
            $customerLink = $customerUuid === '' ? null : $ledger->link($companyUuid, $realm, 'customer', $customerUuid);
            $link         = $ledger->link($companyUuid, $realm, 'invoice', $uuid);
            $customerId   = is_array($customerLink) === true ? trim((string) ($customerLink['qbo_id'] ?? '')) : '';
            $invoiceId    = is_array($link) === true ? trim((string) ($link['qbo_id'] ?? '')) : '';
            if ($customerId !== '' && $invoiceId !== '') {
                $invoiceIds[] = $invoiceId;
            }
        }
        $localUuids = [];
        foreach ($rows as $row) {
            $uuid = (string) ($row['local_uuid'] ?? '');
            if ($uuid !== '') {
                $localUuids[] = $uuid;
            }
        }
        $this->paymentPrefetchIds = array_fill_keys($invoiceIds, true);
        $this->loadPaymentsForInvoices(
            $connection,
            $invoiceIds,
            array_keys($this->rememberedPaymentIds($ledger, $connection, $localUuids))
        );
    }

    /**
     * A DocNumber match can link an invoice after the block payment prefetch.
     * The invoice is already in memory, so its LinkedTxn ids are loaded once.
     *
     * @param array<string, mixed>                                                                                  $connection
     * @param array<string, array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool}> $planned
     */
    private function resolveNewlyLinkedPayments(SyncLedger $ledger, array $connection, array $planned): void
    {
        if ($this->blockPayments === null) {
            return;
        }

        $known   = $this->paymentPrefetchIds ?? [];
        $company = (string) $connection['company_uuid'];
        $realm   = (string) $connection['realm_id'];
        $missing = [];
        foreach (array_keys($planned) as $uuid) {
            $invoiceId = $this->linkedQuickBooksId($ledger, $company, $realm, 'invoice', (string) $uuid);
            if ($invoiceId === '' || isset($known[$invoiceId]) === true || isset($this->blockPayments[$invoiceId]) === true) {
                continue;
            }
            $customerUuid = (string) ($ledger->invoices[(string) $uuid]['customer_uuid'] ?? '');
            if ($this->linkedQuickBooksId($ledger, $company, $realm, 'customer', $customerUuid) !== '') {
                $missing[$invoiceId] = true;
            }
        }
        if ($missing === []) {
            return;
        }

        $invoiceIds = [];
        foreach (array_keys($missing) as $invoiceId) {
            if (is_array($this->invoiceById[$invoiceId] ?? null) === false) {
                continue;
            }
            $invoiceIds[]                                  = (string) $invoiceId;
            $this->paymentPrefetchIds[(string) $invoiceId] = true;
        }
        $this->loadPaymentsForInvoices($connection, $invoiceIds);
    }

    /**
     * Payment ids come from invoices already loaded. Each payment is read once.
     *
     * @param array<string, mixed> $connection
     * @param array<int, string>   $invoiceIds
     * @param array<int, string>   $alsoPaymentIds Payment ids remembered locally for these invoices
     */
    private function loadPaymentsForInvoices(array $connection, array $invoiceIds, array $alsoPaymentIds = []): void
    {
        $paymentIds = [];
        foreach ($alsoPaymentIds as $paymentId) {
            $paymentId = trim($paymentId);
            if ($paymentId !== '') {
                $paymentIds[$paymentId] = true;
            }
        }
        foreach ($invoiceIds as $invoiceId) {
            $invoice = $this->invoiceById[$invoiceId] ?? null;
            if (is_array($invoice) === false) {
                continue;
            }
            foreach ($this->linkedPaymentIds($invoice) as $paymentId) {
                $paymentIds[$paymentId] = true;
            }
        }
        $this->loadPayments($connection, array_keys($paymentIds));
        $this->assignBlockPayments($invoiceIds);
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<int, string>   $ids
     */
    private function loadPayments(array $connection, array $ids): void
    {
        if ($this->paymentById === null) {
            $this->paymentById = [];
        }
        $missing = [];
        foreach ($ids as $paymentId) {
            $paymentId = trim($paymentId);
            if ($paymentId === '' || array_key_exists($paymentId, $this->paymentById) === true || isset($this->paymentReadFailed[$paymentId]) === true) {
                continue;
            }
            $missing[] = $paymentId;
        }
        if ($missing === []) {
            return;
        }

        $loaded = $this->queryWhereIn(
            $connection,
            'Payment',
            'Id',
            $missing,
            fn (string $paymentId): ?array => $this->client->getPayment($connection, $paymentId)
        );
        $found = [];
        foreach ($loaded['rows'] as $payment) {
            $paymentId = trim((string) ($payment['Id'] ?? ''));
            if ($paymentId === '') {
                continue;
            }
            $this->paymentById[$paymentId] = $payment;
            $found[$paymentId]             = true;
        }
        foreach ($loaded['failed'] as $paymentId => $error) {
            $this->paymentReadFailed[(string) $paymentId] = $error;
        }
        foreach ($missing as $paymentId) {
            if (isset($found[$paymentId]) === false && isset($this->paymentReadFailed[$paymentId]) === false) {
                $this->paymentById[$paymentId] = null;
            }
        }
    }

    /**
     * @param array<int, string> $invoiceIds
     */
    private function assignBlockPayments(array $invoiceIds): void
    {
        if ($this->blockPayments === null || $this->paymentById === null) {
            return;
        }
        foreach ($invoiceIds as $invoiceId) {
            $invoice = $this->invoiceById[$invoiceId] ?? null;
            if (is_array($invoice) === false) {
                continue;
            }
            $payments = [];
            foreach ($this->linkedPaymentIds($invoice) as $paymentId) {
                $payment = $this->paymentById[$paymentId] ?? null;
                if (is_array($payment) === true) {
                    $payments[] = $payment;
                }
            }
            if ($payments !== []) {
                $this->blockPayments[$invoiceId] = $payments;
            }
        }
    }

    /**
     * @param array<string, mixed>                                                                                  $connection
     * @param array<string, array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool}> $planned
     * @param array<string, mixed>                                                                                  $settings
     */
    private function prefetchLinkedPayments(array $connection, SyncLedger $ledger, array $planned, array $settings): void
    {
        if ($this->entityPaused($settings, 'payment') === true) {
            $this->paymentById = [];

            return;
        }
        if ($planned === []) {
            return;
        }

        $ids = [];
        foreach ($this->paymentLinkRows($ledger, $connection) as $link) {
            $paymentId = trim((string) ($link['qbo_id'] ?? ''));
            if ($paymentId !== '') {
                $ids[] = $paymentId;
            }
        }
        $this->loadPayments($connection, $ids);
    }
}
