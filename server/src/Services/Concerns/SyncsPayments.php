<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\PaymentTokens;
use Fleetbase\Quickbooks\Support\QuickBooksChanges;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\Translation;

/**
 * SyncEngine's payment sync: sending Fleetbase payments to QuickBooks, reading QuickBooks payments
 * back and keeping payment links. It shares the engine's state, so it lives here only to keep
 * SyncEngine.php readable.
 */
trait SyncsPayments
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
        $payments    = $this->paymentsTouchingInvoice($ledger, $connection, $invoiceUuid, $invoiceId, $remote, $remotes);
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
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $settings
     */
    private function syncPayment(SyncLedger $ledger, array $connection, array $invoice, string $customerRef, string $invoiceId, array $settings): ?string
    {
        if ($invoiceId === '') {
            return null;
        }

        $payDirection = $this->direction($settings, 'payment');
        if ($this->entityPaused($settings, 'payment') === true) {
            return null;
        }
        $copyPay = $this->copiesRemote($payDirection);
        $pushPay = $this->pushesRemote($payDirection);

        $reference         = (string) ($settings['payment_reference'] ?? 'fleetbase');
        $conflict          = (string) ($settings['payment_conflict'] ?? 'fleetbase');
        $quickbooksPrimary = ($reference === 'quickbooks' || $conflict === 'quickbooks') && $copyPay === true;
        $status            = (string) ($invoice['status'] ?? '');
        $pushesPayment     = in_array($status, ['paid', 'partial'], true);
        if ($pushesPayment === false && $quickbooksPrimary === false) {
            return null;
        }

        $uuid          = (string) $invoice['uuid'];
        $applied       = $this->paymentsApplied($ledger, $connection, $uuid, $invoiceId);
        $payments      = $applied['payments'];
        $remoteInvoice = $applied['invoice'];
        if ($payments === []) {
            // QuickBooks keeps the payments and shows none for this unpaid invoice, so an amount
            // Fleetbase still holds (a payment deleted in QuickBooks) comes from the balance.
            $unbacked = $quickbooksPrimary === true
                && $pushesPayment === false
                && $applied['missing'] === false
                && (int) ($invoice['amount_paid'] ?? 0) !== 0;
            if ($unbacked === true && is_array($remoteInvoice) === true) {
                return $this->applyAmountPaidFromBalance($ledger, $uuid, $remoteInvoice) === true ? 'updated' : null;
            }
            if ($applied['missing'] === true || $pushesPayment === false || $applied['settled'] === true || $pushPay === false) {
                return null;
            }

            $amountCents = $this->paymentAmountCents($invoice, $remoteInvoice);
            if ($amountCents <= 0) {
                return null;
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

                return null;
            }
            $created = $this->client->createPayment($connection, $payload);
            $this->storePaymentLink($ledger, $connection, $uuid, $invoiceId, $created, $remoteInvoice);
            $this->rememberInvoicesAfterPayments($ledger, $connection, [$uuid => $invoiceId], $settings);

            return null;
        }

        // Read before the links below store the tokens QuickBooks returns now.
        $paymentChanged = $this->paymentsChangedInQuickBooks($ledger, $connection, $uuid, $payments);
        foreach ($payments as $remote) {
            $this->storePaymentLink($ledger, $connection, $uuid, $invoiceId, $remote, $remoteInvoice);
        }

        if ($pushesPayment === false) {
            $before = $ledger->invoices[$uuid];
            $this->applyPaymentsFromRemote($ledger, $uuid, $payments, $invoiceId);
            $this->applyPaymentStatus($ledger, $uuid, $remoteInvoice);

            return $this->paymentRecordChanged($before, $ledger->invoices[$uuid]) === true ? 'updated' : null;
        }

        if ($this->paymentsMatch($invoice, $payments, $invoiceId, $remoteInvoice) === true) {
            $copies = $quickbooksPrimary === true || ($pushPay === false && $copyPay === true);

            return $copies === true ? $this->markSettledPaid($ledger, $uuid, $remoteInvoice) : null;
        }

        if ($conflict === 'quickbooks' && $copyPay === true && $paymentChanged === true) {
            $before = $ledger->invoices[$uuid];
            $this->applyPaymentsFromRemote($ledger, $uuid, $payments, $invoiceId);
            $this->applyPaymentStatus($ledger, $uuid, $remoteInvoice);
            $changed = $this->paymentRecordChanged($before, $ledger->invoices[$uuid]);
            $this->notePrimaryCopy('invoice', $uuid, $pushPay, $changed);

            return $changed === true ? 'updated' : null;
        }

        if ($pushPay === false) {
            if ($copyPay === true) {
                $before = $ledger->invoices[$uuid];
                $this->applyPaymentsFromRemote($ledger, $uuid, $payments, $invoiceId);
                $this->applyPaymentStatus($ledger, $uuid, $remoteInvoice);

                return $this->paymentRecordChanged($before, $ledger->invoices[$uuid]) === true ? 'updated' : null;
            }

            return null;
        }

        if (count($payments) !== 1) {
            $this->lastError = 'quickbooks.messages.invoice-payments-multiple';

            return 'skipped';
        }

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

        $updated = $this->client->updatePayment($connection, (string) $remote['Id'], (string) $remote['SyncToken'], $payload);
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
     * @return array{payments: array<int, array<string, mixed>>, settled: bool, invoice: array<string, mixed>|null, missing: bool}
     */
    private function paymentsApplied(SyncLedger $ledger, array $connection, string $invoiceUuid, string $invoiceId): array
    {
        $remoteInvoice = $this->remoteInvoice($connection, $invoiceId);
        $ids           = [];
        $listed        = [];
        if (is_array($remoteInvoice) === true) {
            foreach ($this->linkedPaymentIds($remoteInvoice) as $id) {
                $ids[$id]    = true;
                $listed[$id] = true;
            }
        }
        if ($this->blockPayments !== null) {
            foreach ($this->blockPayments[$invoiceId] ?? [] as $payment) {
                if (is_array($payment) === false) {
                    continue;
                }
                $id = trim((string) ($payment['Id'] ?? ''));
                if ($id !== '') {
                    $ids[$id]    = true;
                    $listed[$id] = true;
                }
            }
        }
        $legacy = $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'payment', $invoiceUuid);
        if (is_array($legacy) === true) {
            $id = trim((string) ($legacy['qbo_id'] ?? ''));
            if ($id !== '') {
                $ids[$id]    = true;
                $listed[$id] = true;
            }
        }
        // A payment stored under its QuickBooks id is absent from the invoice body after
        // that link moves. The payment-invoice row is the local record that it still applies.
        $remembered = $this->rememberedPaymentIds($ledger, $connection, [$invoiceUuid]);
        foreach (array_keys($remembered) as $id) {
            $ids[$id] = true;
        }
        foreach (array_keys($ids) as $id) {
            if (isset($this->paymentReadFailed[$id]) === true) {
                throw $this->paymentReadFailed[$id];
            }
        }

        $payments = [];
        $missing  = false;
        foreach (array_keys($ids) as $id) {
            $remote = $this->readPayment($connection, $id);
            if (is_array($remote) === false) {
                $missing = true;
                continue;
            }
            if (isset($listed[$id]) === false && $this->linkedLineCents($remote, $invoiceId) === null) {
                $missing = true;
                continue;
            }
            $payments[$id] = $remote;
        }
        foreach ($this->paymentLinkRows($ledger, $connection) as $link) {
            $id    = trim((string) ($link['qbo_id'] ?? ''));
            $local = (string) ($link['local_uuid'] ?? '');
            if ($id === '' || $local !== $id || isset($payments[$id]) === true || isset($ids[$id]) === true || isset($this->paymentReadFailed[$id]) === true) {
                continue;
            }
            $remote = $this->readPayment($connection, $id);
            if (is_array($remote) === true && $this->linkedLineCents($remote, $invoiceId) !== null) {
                $payments[$id] = $remote;
            }
        }

        $settled = false;
        if (is_array($remoteInvoice) === true) {
            $balance  = $remoteInvoice['Balance'] ?? null;
            $currency = (string) ($ledger->invoices[$invoiceUuid]['currency'] ?? '');
            if ($balance !== null && $balance !== '' && $this->majorUnits($balance, $currency) === 0) {
                $settled = true;
            }
        }
        if ($payments !== []) {
            $settled = true;
        }

        return [
            'payments' => array_values($payments),
            'settled'  => $settled,
            'invoice'  => is_array($remoteInvoice) === true ? $remoteInvoice : null,
            'missing'  => $missing === true && $payments === [],
        ];
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
    private function paymentsTouchingInvoice(SyncLedger $ledger, array $connection, string $invoiceUuid, string $invoiceId, array $changed, array $alreadyRead): array
    {
        $payments = $this->seedPaymentsTouchingInvoice($changed, $alreadyRead, $invoiceId);
        $missing  = $this->missingPaymentLinks($ledger, $connection, $invoiceUuid, $payments);
        foreach ($this->readRemoteIds($connection, 'Payment', array_keys($missing)) as $remoteId => $remote) {
            $local = $missing[$remoteId] ?? '';
            if ($local === $invoiceUuid || ($invoiceId !== '' && $this->linkedLineCents($remote, $invoiceId) !== null)) {
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
     * @param array<string, mixed>                $connection
     * @param array<string, array<string, mixed>> $payments
     *
     * @return array<string, string>
     */
    private function missingPaymentLinks(SyncLedger $ledger, array $connection, string $invoiceUuid, array $payments): array
    {
        $missing = [];
        foreach ($this->paymentLinkRows($ledger, $connection) as $link) {
            $remoteId = trim((string) ($link['qbo_id'] ?? ''));
            $local    = (string) ($link['local_uuid'] ?? '');
            if ($remoteId === '' || isset($payments[$remoteId]) === true || ($local !== $invoiceUuid && $local !== $remoteId)) {
                continue;
            }
            $missing[$remoteId] = $local;
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    private function readPayment(array $connection, string $id): ?array
    {
        if (isset($this->paymentReadFailed[$id]) === true) {
            throw $this->paymentReadFailed[$id];
        }
        if ($this->paymentById !== null) {
            if (array_key_exists($id, $this->paymentById) === false) {
                return null;
            }
            $cached = $this->paymentById[$id];

            return is_array($cached) === true ? $cached : null;
        }

        return $this->client->getPayment($connection, $id);
    }

    /**
     * One stored link per QuickBooks payment id. A payment that cannot be found
     * again from the invoice or its own lines keeps the invoice uuid so the next
     * sync still sees it. A later payment never replaces an earlier id.
     *
     * @param array<string, mixed>      $connection
     * @param array<string, mixed>      $remote
     * @param array<string, mixed>|null $remoteInvoice
     */
    private function storePaymentLink(SyncLedger $ledger, array $connection, string $invoiceUuid, string $invoiceId, array $remote, ?array $remoteInvoice = null): void
    {
        $paymentId = trim((string) ($remote['Id'] ?? ''));
        if ($paymentId === '') {
            return;
        }
        $remoteInvoice  = $this->cachedRemoteInvoice($remoteInvoice, $invoiceId);
        $company        = (string) $connection['company_uuid'];
        $realm          = (string) $connection['realm_id'];
        $legacy         = $ledger->link($company, $realm, 'payment', $invoiceUuid);
        $legacyId       = is_array($legacy) === true ? trim((string) ($legacy['qbo_id'] ?? '')) : '';
        $rediscoverable = $this->paymentRediscoverable($remote, $invoiceId, $remoteInvoice);
        $byPayment      = $this->storeByPaymentId($rediscoverable, $legacyId, $paymentId);
        $attributes     = $this->paymentLinkAttributes($ledger, $connection, $invoiceUuid, $paymentId, $remote, $byPayment, $rediscoverable, $legacyId);
        $attributes     = $this->withInvoiceToken($ledger, $attributes, $invoiceUuid, $legacy, $remote);
        $action         = $this->paymentLinkAction($ledger, $company, $realm, $paymentId, $byPayment, $legacy, $legacyId);
        if ($action === 'rekey') {
            $this->rekeyPaymentLink($ledger, $company, $realm, $invoiceUuid, $attributes);

            return;
        }

        $ledger->putLink($attributes);
        if ($action === 'forget') {
            $this->forgetPaymentLink($ledger, $company, $realm, $invoiceUuid);
        }
    }

    /**
     * Whether QuickBooks changed one of these payments since this invoice last synced it. Each
     * payment is compared with its own link, or with the older link stored under the invoice,
     * using the token kept for this invoice (see PaymentTokens).
     *
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $payments
     */
    private function paymentsChangedInQuickBooks(
        SyncLedger $ledger,
        array $connection,
        string $invoiceUuid,
        array $payments,
    ): bool {
        $company = (string) $connection['company_uuid'];
        $realm   = (string) $connection['realm_id'];
        $legacy  = $ledger->link($company, $realm, 'payment', $invoiceUuid);
        foreach ($payments as $remote) {
            $id   = trim((string) ($remote['Id'] ?? ''));
            $link = $id === '' ? null : $ledger->link($company, $realm, 'payment', $id);
            if ($link === null && is_array($legacy) === true && trim((string) ($legacy['qbo_id'] ?? '')) === $id) {
                $link = $legacy;
            }
            if (QuickBooksChanges::since(PaymentTokens::forInvoice($link, $invoiceUuid), $remote) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Applying a payment raises the SyncToken of the QuickBooks invoice it pays. Each invoice is
     * read again, so a payment Fleetbase sent does not look like a QuickBooks change of the
     * invoice on the next sync; the token moves on by that one step only, and only when the
     * invoice read is still in sync: a payment that did not raise the token would otherwise hide
     * a QuickBooks edit of that one step. Only QuickBooks Primary in two-way sync compares the
     * token. A failed read keeps the older token: the next sync then lets QuickBooks win once.
     *
     * @param array<string, mixed>  $connection
     * @param array<string, string> $invoiceIds QuickBooks invoice ids by Fleetbase invoice uuid
     * @param array<string, mixed>  $settings
     */
    private function rememberInvoicesAfterPayments(
        SyncLedger $ledger,
        array $connection,
        array $invoiceIds,
        array $settings,
    ): void {
        $invoiceIds = array_filter($invoiceIds, static fn (string $id): bool => $id !== '');
        if ($invoiceIds === [] || $this->tokenDecides($settings, 'invoice') === false) {
            return;
        }
        try {
            $remotes = $this->readRemoteIds($connection, 'Invoice', array_values($invoiceIds));
        } catch (\Throwable) {
            return;
        }
        $company = (string) $connection['company_uuid'];
        $realm   = (string) $connection['realm_id'];
        foreach ($invoiceIds as $uuid => $id) {
            $uuid   = (string) $uuid;
            $remote = $remotes[$id] ?? null;
            $link   = $ledger->link($company, $realm, 'invoice', $uuid);
            if (is_array($remote) === false || $this->onePaymentLater($link, $remote) === false) {
                continue;
            }
            if ($this->invoiceInSync($ledger, $connection, $uuid, $remote, $settings, self::NO_STORED_LINES) === true) {
                $ledger->putLink($this->linkFrom($connection, 'invoice', $uuid, 'Invoice', $remote));
                $this->rememberWrittenInvoice($remote);
            }
        }
    }

    /**
     * Whether the invoice read after a payment is one QuickBooks version after the token this
     * sync stored: the payment's own step. A larger step means a QuickBooks user also changed the
     * invoice meanwhile, so the older token stays and the next sync lets QuickBooks win once.
     *
     * @param array<string, mixed>|null $link
     * @param array<string, mixed>      $remote
     */
    private function onePaymentLater(?array $link, array $remote): bool
    {
        if ($link === null || (string) ($link['qbo_id'] ?? '') !== (string) ($remote['Id'] ?? '')) {
            return false;
        }
        $stored  = trim((string) ($link['sync_token'] ?? ''));
        $current = trim((string) ($remote['SyncToken'] ?? ''));

        return ctype_digit($stored) === true && $current === (string) ((int) $stored + 1);
    }

    private function storeByPaymentId(bool $rediscoverable, string $legacyId, string $paymentId): bool
    {
        return $rediscoverable === true || ($legacyId !== '' && $legacyId !== $paymentId);
    }

    /**
     * @param array<string, mixed>|null $legacy
     */
    private function paymentLinkAction(SyncLedger $ledger, string $company, string $realm, string $paymentId, bool $byPayment, ?array $legacy, string $legacyId): string
    {
        if ($byPayment === false || is_array($legacy) === false || $legacyId !== $paymentId) {
            return 'keep';
        }
        if ($ledger->link($company, $realm, 'payment', $paymentId) === null) {
            return 'rekey';
        }

        return 'forget';
    }

    /**
     * The payment id is not the invoice. Keep the invoice on the link so a later
     * delete can unmark it after QuickBooks stops returning this payment.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $remote
     *
     * @return array<string, mixed>
     */
    private function paymentLinkAttributes(SyncLedger $ledger, array $connection, string $invoiceUuid, string $paymentId, array $remote, bool $byPayment, bool $rediscoverable, string $legacyId): array
    {
        $company    = (string) $connection['company_uuid'];
        $realm      = (string) $connection['realm_id'];
        $localUuid  = $byPayment === true ? $paymentId : $invoiceUuid;
        $attributes = $this->linkFrom($connection, 'payment', $localUuid, 'Payment', $remote);
        if ($byPayment === false || $invoiceUuid === '' || $invoiceUuid === $paymentId) {
            return $attributes;
        }
        if ($rediscoverable === false && $legacyId !== $paymentId) {
            return $attributes;
        }
        $kept    = $invoiceUuid;
        $already = $ledger->link($company, $realm, 'payment', $paymentId);
        if (is_array($already) === true) {
            $prior = trim((string) ($already['invoice_uuid'] ?? ''));
            if ($prior !== '' && $prior !== $paymentId) {
                $kept = $prior;
            }
        }
        $attributes['invoice_uuid'] = $kept;

        return $attributes;
    }

    /**
     * @param array<string, mixed>      $remote
     * @param array<string, mixed>|null $remoteInvoice
     */
    private function paymentRediscoverable(array $remote, string $invoiceId, ?array $remoteInvoice): bool
    {
        if ($invoiceId !== '' && $this->linkedLineCents($remote, $invoiceId) !== null) {
            return true;
        }
        $paymentId = trim((string) ($remote['Id'] ?? ''));
        if ($paymentId === '' || is_array($remoteInvoice) === false) {
            return false;
        }

        return in_array($paymentId, $this->linkedPaymentIds($remoteInvoice), true);
    }

    /**
     * QuickBooks payment ids remembered for these Fleetbase invoices.
     * payment-invoice.local_uuid is the payment id and qbo_id is the invoice uuid.
     *
     * @param array<string, mixed> $connection
     * @param array<int, string>   $invoiceUuids
     *
     * @return array<string, true>
     */
    private function rememberedPaymentIds(SyncLedger $ledger, array $connection, array $invoiceUuids): array
    {
        $wanted = [];
        foreach ($invoiceUuids as $uuid) {
            $uuid = trim($uuid);
            if ($uuid !== '') {
                $wanted[$uuid] = true;
            }
        }
        if ($wanted === []) {
            return [];
        }

        $company = (string) $connection['company_uuid'];
        $realm   = (string) $connection['realm_id'];
        $ids     = [];
        foreach ($ledger->links as $link) {
            if (is_array($link) === false) {
                continue;
            }
            if ((string) ($link['company_uuid'] ?? '') !== $company || (string) ($link['realm_id'] ?? '') !== $realm) {
                continue;
            }
            if ((string) ($link['local_type'] ?? '') !== 'payment-invoice') {
                continue;
            }
            $invoiceUuid = trim((string) ($link['qbo_id'] ?? ''));
            $paymentId   = trim((string) ($link['local_uuid'] ?? ''));
            if ($paymentId === '' || $invoiceUuid === '' || $paymentId === $invoiceUuid || isset($wanted[$invoiceUuid]) === false) {
                continue;
            }
            $ids[$paymentId] = true;
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<int, array<string, mixed>>
     */
    private function paymentLinkRows(SyncLedger $ledger, array $connection): array
    {
        $company = (string) $connection['company_uuid'];
        $realm   = (string) $connection['realm_id'];
        $rows    = [];
        foreach ($ledger->links as $link) {
            if (is_array($link) === false) {
                continue;
            }
            if ((string) ($link['company_uuid'] ?? '') !== $company || (string) ($link['realm_id'] ?? '') !== $realm) {
                continue;
            }
            if ((string) ($link['local_type'] ?? '') !== 'payment') {
                continue;
            }
            $rows[] = $link;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function rekeyPaymentLink(SyncLedger $ledger, string $company, string $realm, string $fromUuid, array $attributes): void
    {
        foreach ($ledger->links as $index => $link) {
            if (is_array($link) === false || $this->samePaymentLink($link, $company, $realm, $fromUuid) === false) {
                continue;
            }
            $ledger->links[$index] = array_merge($link, $attributes);
            $ledger->rebuildIndex();

            return;
        }
    }

    private function forgetPaymentLink(SyncLedger $ledger, string $company, string $realm, string $localUuid): void
    {
        foreach ($ledger->links as $index => $link) {
            if (is_array($link) === false || $this->samePaymentLink($link, $company, $realm, $localUuid) === false) {
                continue;
            }
            unset($ledger->links[$index]);
            $ledger->links = array_values($ledger->links);
            $ledger->rebuildIndex();

            return;
        }
    }

    /**
     * @param array<string, mixed> $link
     */
    private function samePaymentLink(array $link, string $company, string $realm, string $localUuid): bool
    {
        return (string) ($link['company_uuid'] ?? '') === $company
            && (string) ($link['realm_id'] ?? '') === $realm
            && (string) ($link['local_type'] ?? '') === 'payment'
            && (string) ($link['local_uuid'] ?? '') === $localUuid;
    }

    /**
     * @param array<string, mixed> $invoice
     *
     * @return array<int, string>
     */
    private function linkedPaymentIds(array $invoice): array
    {
        $ids  = [];
        $txns = $invoice['LinkedTxn'] ?? [];
        if (is_array($txns) === false) {
            return [];
        }
        foreach ($txns as $txn) {
            if (is_array($txn) === false || (string) ($txn['TxnType'] ?? '') !== 'Payment') {
                continue;
            }
            $id = trim((string) ($txn['TxnId'] ?? ''));
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
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
                $id = (string) ($txn['TxnId'] ?? '');
                if ($id !== '' && $id !== $invoiceId) {
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
        if (in_array($status, ['void', 'voided', 'cancelled', 'canceled', 'deleted'], true) === true || empty($invoice['deleted_at']) === false) {
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
            $this->writeBuffer = null;
            if ($exception instanceof QuickBooksException && ($exception->isUnauthorized() === true || $exception->isRateLimit() === true)) {
                if ($exception->isUnauthorized() === true) {
                    $this->commitFinished($ledger, $connection, $this->finishedBeforePayment($finished, $queued), $batch, $trigger, $record);
                    $this->tokenRejected($ledger, (string) $connection['company_uuid']);
                    $batch['failed']++;

                    return true;
                }
                $stop = $this->firstQueuedUuid($finished, $queued);
                if ($stop !== null && isset($finished[$stop]) === true) {
                    $finished[$stop]['outcome']   = $this->failure($ledger, $connection, $finished[$stop]['row'], $exception, $settings, $now);
                    $finished[$stop]['error']     = $this->exceptionError($exception);
                    $finished[$stop]['permanent'] = false;
                }
                $this->commitFinished($ledger, $connection, $this->finishedThrough($finished, $stop), $batch, $trigger, $record);

                return true;
            }
            $error = $this->exceptionError($exception);
            foreach (array_keys($queued) as $uuid) {
                if (isset($finished[$uuid]) === false) {
                    continue;
                }
                $finished[$uuid]['outcome']   = $this->failure($ledger, $connection, $finished[$uuid]['row'], $exception, $settings, $now);
                $finished[$uuid]['error']     = $error;
                $finished[$uuid]['permanent'] = false;
            }
            $this->commitFinished($ledger, $connection, $finished, $batch, $trigger, $record);

            return false;
        }

        $this->writeBuffer = null;
        $paidInvoices      = [];
        foreach ($finished as $uuid => $item) {
            $result = $paymentResults['payment:' . $uuid] ?? null;
            if (is_array($result) === false) {
                continue;
            }
            if (empty($result['halt']) === false) {
                $status = (int) ($result['status'] ?? 0);
                if ($status === 401) {
                    $this->commitFinished($ledger, $connection, $this->finishedThrough($finished, (string) $uuid, false), $batch, $trigger, $record);
                    $this->tokenRejected($ledger, (string) $connection['company_uuid']);
                    $batch['failed']++;

                    return true;
                }
                $message                      = (string) ($result['error'] ?? '');
                $finished[$uuid]['outcome']   = $this->failure($ledger, $connection, $item['row'], new QuickBooksException($status, $message), $settings, $now);
                $finished[$uuid]['error']     = $this->batchItemAttemptError($message, $status);
                $finished[$uuid]['permanent'] = false;
                // The payments before this one were sent: their invoices keep the step they took.
                $this->rememberInvoicesAfterPayments($ledger, $connection, $paidInvoices, $settings);
                $this->commitFinished($ledger, $connection, $this->finishedThrough($finished, (string) $uuid, true), $batch, $trigger, $record);

                return true;
            }
            if (empty($result['ok']) === true) {
                $message                      = (string) ($result['error'] ?? '');
                $finished[$uuid]['outcome']   = $this->failure(
                    $ledger,
                    $connection,
                    $item['row'],
                    new QuickBooksException(
                        (int) ($result['status'] ?? 400),
                        $message !== '' ? $message : self::ITEM_REJECTED_MESSAGE
                    ),
                    $settings,
                    $now
                );
                $finished[$uuid]['error']     = $this->batchItemAttemptError($message, (int) ($result['status'] ?? 0));
                $finished[$uuid]['permanent'] = false;
                continue;
            }
            if (is_array($result['body'] ?? null) === true && ($result['body']['Id'] ?? '') !== '') {
                $invoiceLink = $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'invoice', (string) $uuid);
                $invoiceId   = is_array($invoiceLink) === true ? trim((string) ($invoiceLink['qbo_id'] ?? '')) : '';
                $this->storePaymentLink($ledger, $connection, (string) $uuid, $invoiceId, $result['body']);
                $paidInvoices[(string) $uuid] = $invoiceId;
            }
        }
        $this->rememberInvoicesAfterPayments($ledger, $connection, $paidInvoices, $settings);
        $this->commitFinished($ledger, $connection, $finished, $batch, $trigger, $record);

        return false;
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
        /** @var array<string, string> $missing invoice id => customer id */
        $missing = [];
        foreach (array_keys($planned) as $uuid) {
            $link = $ledger->link($company, $realm, 'invoice', (string) $uuid);
            if (is_array($link) === false) {
                continue;
            }
            $invoiceId = trim((string) ($link['qbo_id'] ?? ''));
            if ($invoiceId === '' || isset($known[$invoiceId]) === true || isset($this->blockPayments[$invoiceId]) === true) {
                continue;
            }
            $invoice      = $ledger->invoices[(string) $uuid] ?? null;
            $customerUuid = is_array($invoice) === true ? (string) ($invoice['customer_uuid'] ?? '') : '';
            $customerLink = $customerUuid === '' ? null : $ledger->link($company, $realm, 'customer', $customerUuid);
            $customerId   = is_array($customerLink) === true ? trim((string) ($customerLink['qbo_id'] ?? '')) : '';
            if ($customerId === '') {
                continue;
            }
            $missing[$invoiceId] = $customerId;
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
        foreach ($alsoPaymentIds as $id) {
            $id = trim($id);
            if ($id !== '') {
                $paymentIds[$id] = true;
            }
        }
        foreach ($invoiceIds as $invoiceId) {
            $invoice = $this->invoiceById[$invoiceId] ?? null;
            if (is_array($invoice) === false) {
                continue;
            }
            foreach ($this->linkedPaymentIds($invoice) as $id) {
                $paymentIds[$id] = true;
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
        foreach ($ids as $id) {
            $id = trim($id);
            if ($id === '' || array_key_exists($id, $this->paymentById) === true || isset($this->paymentReadFailed[$id]) === true) {
                continue;
            }
            $missing[] = $id;
        }
        if ($missing === []) {
            return;
        }

        $loaded = $this->queryWhereIn(
            $connection,
            'Payment',
            'Id',
            $missing,
            fn (string $id): ?array => $this->client->getPayment($connection, $id)
        );
        $found = [];
        foreach ($loaded['rows'] as $payment) {
            $id = trim((string) ($payment['Id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $this->paymentById[$id] = $payment;
            $found[$id]             = true;
        }
        foreach ($loaded['failed'] as $id => $error) {
            $this->paymentReadFailed[(string) $id] = $error;
        }
        foreach ($missing as $id) {
            if (isset($found[$id]) === false && isset($this->paymentReadFailed[$id]) === false) {
                $this->paymentById[$id] = null;
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
            foreach ($this->linkedPaymentIds($invoice) as $id) {
                $payment = $this->paymentById[$id] ?? null;
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
            $id = trim((string) ($link['qbo_id'] ?? ''));
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        $this->loadPayments($connection, $ids);
    }
}
