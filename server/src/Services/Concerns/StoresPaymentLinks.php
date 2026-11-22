<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\PaymentTokens;
use Fleetbase\Quickbooks\Support\QuickBooksChanges;

/**
 * SyncEngine's payment links: which QuickBooks payment belongs to which Fleetbase invoice, and
 * the sync tokens that tell whether QuickBooks changed a payment.
 */
trait StoresPaymentLinks
{
    /**
     * @param array<string, mixed>                $connection
     * @param array<string, array<string, mixed>> $payments
     *
     * @return array<string, string>
     */
    private function missingPaymentLinks(
        SyncLedger $ledger,
        array $connection,
        string $invoiceUuid,
        array $payments,
    ): array {
        $missing = [];
        foreach ($this->paymentLinkRows($ledger, $connection) as $link) {
            $remoteId = trim((string) ($link['qbo_id'] ?? ''));
            $local    = (string) ($link['local_uuid'] ?? '');
            if (
                $remoteId === ''
                || isset($payments[$remoteId]) === true
                || ($local !== $invoiceUuid && $local !== $remoteId)
            ) {
                continue;
            }
            $missing[$remoteId] = $local;
        }

        return $missing;
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
    private function storePaymentLink(
        SyncLedger $ledger,
        array $connection,
        string $invoiceUuid,
        string $invoiceId,
        array $remote,
        ?array $remoteInvoice = null,
    ): void {
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
        $attributes     = $this->paymentLinkAttributes(
            $ledger,
            $connection,
            $invoiceUuid,
            $paymentId,
            $remote,
            $byPayment,
            $rediscoverable,
            $legacyId
        );
        $attributes     = $this->withInvoiceToken($ledger, $attributes, $invoiceUuid, $legacy, $remote);
        $action         = $this->paymentLinkAction(
            $ledger,
            $company,
            $realm,
            $paymentId,
            $byPayment,
            $legacy,
            $legacyId
        );
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
            $paymentId   = trim((string) ($remote['Id'] ?? ''));
            $link        = $paymentId === '' ? null : $ledger->link($company, $realm, 'payment', $paymentId);
            if (
                $link === null
                && is_array($legacy) === true
                && trim((string) ($legacy['qbo_id'] ?? '')) === $paymentId
            ) {
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
        $invoiceIds = array_filter($invoiceIds, static fn (string $invoiceId): bool => $invoiceId !== '');
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
        foreach ($invoiceIds as $uuid => $invoiceId) {
            $uuid   = (string) $uuid;
            $remote = $remotes[$invoiceId] ?? null;
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
    private function paymentLinkAction(
        SyncLedger $ledger,
        string $company,
        string $realm,
        string $paymentId,
        bool $byPayment,
        ?array $legacy,
        string $legacyId,
    ): string {
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
    private function paymentLinkAttributes(
        SyncLedger $ledger,
        array $connection,
        string $invoiceUuid,
        string $paymentId,
        array $remote,
        bool $byPayment,
        bool $rediscoverable,
        string $legacyId,
    ): array {
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
            if (
                $paymentId === ''
                || $invoiceUuid === ''
                || $paymentId === $invoiceUuid
                || isset($wanted[$invoiceUuid]) === false
            ) {
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
    private function rekeyPaymentLink(
        SyncLedger $ledger,
        string $company,
        string $realm,
        string $fromUuid,
        array $attributes,
    ): void {
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
            $paymentId = trim((string) ($txn['TxnId'] ?? ''));
            if ($paymentId !== '') {
                $ids[$paymentId] = true;
            }
        }

        return array_keys($ids);
    }
}
