<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\PaymentTokens;
use Fleetbase\Quickbooks\Support\QuickBooksChanges;
use Fleetbase\Quickbooks\Support\Translation;

/**
 * SyncEngine's copies from QuickBooks invoices: number, lines, note, dates, customer, currency
 * and paid state, and the QuickBooks customer an invoice keeps. Split from SyncsInvoices to keep each file readable.
 */
trait CopiesRemoteInvoices
{
    /**
     * Copy the dates QuickBooks holds and Fleetbase lacks. The strict compare leaves an empty
     * Fleetbase date out when QuickBooks supplies the invoice, so a match does not copy them.
     *
     * @param array<string, mixed> $remote
     */
    private function copyMissingInvoiceDates(SyncLedger $ledger, string $uuid, array $remote): bool
    {
        $changed = false;
        foreach (['TxnDate' => 'date', 'DueDate' => 'due_date'] as $remoteKey => $local) {
            $value = trim((string) ($remote[$remoteKey] ?? ''));
            if ($value !== '' && trim((string) ($ledger->invoices[$uuid][$local] ?? '')) === '') {
                $ledger->invoices[$uuid][$local] = $value;
                $changed                         = true;
            }
        }

        return $changed;
    }

    /**
     * The QuickBooks customer of an invoice whose Fleetbase customer link this run did not load:
     * the invoice's own QuickBooks customer when that is linked to the same Fleetbase customer
     * (an invoice that followed it, followKeptCustomer), otherwise none.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $remote
     */
    private function unloadedCustomerRef(SyncLedger $ledger, array $connection, array $remote, string $owner): string
    {
        $remoteCustomer = $this->remoteInvoiceCustomerId($remote);
        if ($owner === '' || $remoteCustomer === '') {
            return '';
        }
        $local = $this->remoteInvoiceLocalCustomer($ledger, (string) $connection['realm_id'], $remoteCustomer);

        return $local === $owner ? $remoteCustomer : '';
    }

    /**
     * The QuickBooks customer an invoice is compared with and sent to. QuickBooks as Primary in
     * two-way sync keeps a customer QuickBooks moved the invoice to when Fleetbase has not linked
     * it: the Fleetbase customer stays (Activity says so), the invoice counts as in sync, and a
     * later Fleetbase edit does not move it back. A Fleetbase customer change made since is a
     * Fleetbase edit and is sent (reassignedWhileKept). Otherwise the Fleetbase customer's own.
     *
     * @param array<string, mixed>      $connection
     * @param array<string, mixed>      $settings
     * @param array<string, mixed>      $remote
     * @param array<string, mixed>|null $link       the invoice link
     */
    private function keptCustomerRef(
        SyncLedger $ledger,
        array $connection,
        array $settings,
        string $customerRef,
        array $remote,
        string $customerUuid,
        ?array $link,
    ): string {
        $remoteCustomer = $this->remoteInvoiceCustomerId($remote);
        $moved          = $remoteCustomer !== '' && $remoteCustomer !== trim($customerRef);
        if ($moved === false || $this->tokenDecides($settings, 'invoice') === false) {
            return $customerRef;
        }
        if ($this->reassignedWhileKept($link, $remote, $customerUuid) === true) {
            return $customerRef;
        }
        $local = $this->remoteInvoiceLocalCustomer($ledger, (string) $connection['realm_id'], $remoteCustomer);

        return $local === '' ? $remoteCustomer : $customerRef;
    }

    /**
     * Whether Fleetbase moved an invoice to another customer while it was kept on a QuickBooks
     * customer, and QuickBooks did not change the invoice since.
     *
     * @param array<string, mixed>|null $link   the invoice link
     * @param array<string, mixed>      $remote
     */
    private function reassignedWhileKept(?array $link, array $remote, string $customerUuid): bool
    {
        if ($link === null || QuickBooksChanges::since($link, $remote) === true) {
            return false;
        }
        $kept = trim((string) ($link[self::KEPT_CUSTOMER] ?? ''));
        $from = trim((string) ($link[self::KEPT_FROM] ?? ''));

        return $kept !== '' && $kept === $this->remoteInvoiceCustomerId($remote)
            && $from !== '' && $customerUuid !== '' && $from !== $customerUuid;
    }

    /**
     * Store an invoice link for the QuickBooks invoice as read or written, with the customer it
     * is kept on (keptInvoiceCustomer).
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $remote
     * @param array<string, mixed> $settings
     */
    private function putInvoiceLink(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $remote,
        array $settings,
        ?string $token = null,
    ): void {
        $link = $this->linkFrom($connection, 'invoice', $uuid, 'Invoice', $remote);
        if ($token !== null) {
            $link['sync_token'] = $token;
        }
        $ledger->putLink($this->keptInvoiceCustomer($ledger, $connection, $settings, $uuid, $remote) + $link);
    }

    /**
     * The QuickBooks customer an invoice is kept on (keptCustomerRef) and the Fleetbase customer
     * it kept, as invoice link columns, so that the invoice follows that QuickBooks customer once
     * Fleetbase links it (followKeptCustomer). Both null when the invoice is not kept.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $remote
     *
     * @return array{kept_customer: string|null, kept_from: string|null}
     */
    private function keptInvoiceCustomer(
        SyncLedger $ledger,
        array $connection,
        array $settings,
        string $uuid,
        array $remote,
    ): array {
        $company      = (string) $connection['company_uuid'];
        $realm        = (string) $connection['realm_id'];
        $customerUuid = (string) ($ledger->invoices[$uuid]['customer_uuid'] ?? '');
        $customerLink = $ledger->link($company, $realm, 'customer', $customerUuid);
        $ref          = is_array($customerLink) === true ? trim((string) ($customerLink['qbo_id'] ?? '')) : '';
        $link         = $ledger->link($company, $realm, 'invoice', $uuid);
        $kept         = $this->keptCustomerRef($ledger, $connection, $settings, $ref, $remote, $customerUuid, $link);
        if ($kept === $ref) {
            return [self::KEPT_CUSTOMER => null, self::KEPT_FROM => null];
        }

        return [self::KEPT_CUSTOMER => $kept, self::KEPT_FROM => $customerUuid];
    }

    /**
     * QuickBooks as Primary in two-way sync: an invoice kept on a QuickBooks customer Fleetbase
     * had not linked moves to the Fleetbase customer now linked to it, so the QuickBooks record
     * wins and a later Fleetbase push does not move the invoice back. Only while QuickBooks
     * still has the invoice on that customer and Fleetbase did not move it meanwhile. True when
     * the Fleetbase invoice changed.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $remote
     */
    private function followKeptCustomer(
        SyncLedger $ledger,
        array $connection,
        array $settings,
        string $uuid,
        array $remote,
    ): bool {
        $realm = (string) $connection['realm_id'];
        $link  = $ledger->link((string) $connection['company_uuid'], $realm, 'invoice', $uuid);
        if ($link === null || $this->tokenDecides($settings, 'invoice') === false) {
            return false;
        }
        $kept    = trim((string) ($link[self::KEPT_CUSTOMER] ?? ''));
        $from    = trim((string) ($link[self::KEPT_FROM] ?? ''));
        $current = (string) ($ledger->invoices[$uuid]['customer_uuid'] ?? '');
        if ($kept === '' || $kept !== $this->remoteInvoiceCustomerId($remote) || ($from !== '' && $from !== $current)) {
            return false;
        }
        $local = $this->remoteInvoiceLocalCustomer($ledger, $realm, $kept);
        if ($local === '') {
            return false;
        }
        $ledger->putLink(array_merge($link, [self::KEPT_CUSTOMER => null, self::KEPT_FROM => null]));
        if ($local === $current) {
            return false;
        }
        $ledger->invoices[$uuid]['customer_uuid'] = $local;

        return true;
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function copyInvoiceNumber(SyncLedger $ledger, string $uuid, array $remote, string $reference): bool
    {
        if ($reference !== 'quickbooks') {
            return false;
        }

        $number = trim((string) ($remote['DocNumber'] ?? ''));
        if ($number === '' || $number === (string) ($ledger->invoices[$uuid]['number'] ?? '')) {
            return false;
        }

        $ledger->invoices[$uuid]['number'] = $number;
        $ledger->rememberInvoice($uuid, $ledger->invoices[$uuid]);

        return true;
    }

    /**
     * @param array<string, mixed>              $remote
     * @param array<string, array<int, string>> $taxCodes sales tax rates by tax code (taxCodesFor)
     * @param bool                              $ownsTax  Fleetbase works out the tax: lines keep their
     *                                                    Fleetbase rate (InvoiceMapper::withFleetbaseRates)
     */
    private function applyInvoiceFromRemote(
        SyncLedger $ledger,
        string $uuid,
        array $remote,
        string $reference,
        string $realmId = '',
        bool $clears = false,
        array $taxCodes = [],
        bool $ownsTax = false,
    ): bool {
        // Both copies run: a number change must not skip the customer and currency copy.
        $numberChanged = $this->copyInvoiceNumber($ledger, $uuid, $remote, $reference) === true;
        $partyChanged  = $this->copyInvoiceCustomerAndCurrency($ledger, $uuid, $remote) === true;
        $changed       = $numberChanged || $partyChanged;
        foreach (['TxnDate' => 'date', 'DueDate' => 'due_date'] as $remoteKey => $local) {
            $value = (string) ($remote[$remoteKey] ?? '');
            if ($value !== '' && (string) ($ledger->invoices[$uuid][$local] ?? '') !== $value) {
                $ledger->invoices[$uuid][$local] = $value;
                $changed                         = true;
            }
        }
        if ($this->copyInvoiceNote($ledger, $uuid, $remote, $clears) === true) {
            $changed = true;
        }

        $current = $ledger->invoices[$uuid]['items'] ?? [];
        $current = is_array($current) === true ? $current : [];
        $mapped  = $this->remoteInvoiceLines($ledger->invoices[$uuid], $uuid, $remote, $taxCodes, $ownsTax);
        if ($mapped['items'] !== [] && $mapped['items'] !== $this->hashableInvoiceItems($current)) {
            $ledger->invoices[$uuid]['items']                 = $this->invoiceMapper($ledger->invoices[$uuid])
                ->withLineProducts($mapped['items'], $remote, $current, $this->lineProductItems());
            $ledger->invoices[$uuid]['items_from_quickbooks'] = true;
            $changed                                          = true;
            $this->recordReplacedLines($ledger, $uuid, $remote, $realmId);
        }
        if ((int) ($ledger->invoices[$uuid]['total'] ?? 0) !== $mapped['total']) {
            $ledger->invoices[$uuid]['total'] = $mapped['total'];
            $changed                          = true;
        }
        if ((int) ($ledger->invoices[$uuid]['tax'] ?? 0) !== $mapped['tax']) {
            $ledger->invoices[$uuid]['tax'] = $mapped['tax'];
            $changed                        = true;
        }
        if ($changed === true) {
            $ledger->invoices[$uuid]['replace_from_quickbooks'] = true;
        }

        return $changed;
    }

    /**
     * The QuickBooks lines, tax and total as a copy gives them to a Fleetbase invoice
     * (InvoiceMapper::fromQuickBooks). When Fleetbase works out the tax ($ownsTax) the lines keep
     * their Fleetbase rates instead (InvoiceMapper::withFleetbaseRates), and Activity notes lines
     * only QuickBooks has, which get no Fleetbase tax.
     *
     * @param array<string, mixed>              $invoice
     * @param array<string, mixed>              $remote
     * @param array<string, array<int, string>> $taxCodes
     *
     * @return array{items: array<int, array<string, mixed>>, tax: int, total: int}
     */
    private function remoteInvoiceLines(
        array $invoice,
        string $uuid,
        array $remote,
        array $taxCodes,
        bool $ownsTax,
    ): array {
        $current = is_array($invoice['items'] ?? null) === true ? $invoice['items'] : [];
        $mapper  = $this->invoiceMapper($invoice);
        $mapped  = $mapper->fromQuickBooks($remote, $current, $taxCodes);
        if ($ownsTax === false) {
            return $mapped;
        }
        $mapped = $mapper->withFleetbaseRates($mapped, $remote, $current);
        if ($mapped['unpaired'] > 0) {
            $this->rowNotes['invoice:' . $uuid] = Translation::encode('quickbooks.messages.invoice-tax-new-line');
        }

        return $mapped;
    }

    /**
     * QuickBooks holds a longer Fleetbase note cut to its limit. That copy is not a change. An
     * empty QuickBooks note is copied only with $clears (see copiesClears).
     *
     * @param array<string, mixed> $remote
     */
    private function copyInvoiceNote(SyncLedger $ledger, string $uuid, array $remote, bool $clears): bool
    {
        $note  = (string) ($remote['PrivateNote'] ?? '');
        $local = InvoiceMapper::privateNote((string) ($ledger->invoices[$uuid]['notes'] ?? ''));
        if (($note === '' && $clears === false) || $note === $local) {
            return false;
        }
        $ledger->invoices[$uuid]['notes'] = $note;

        return true;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    private function remoteInvoice(array $connection, string $invoiceId): ?array
    {
        if ($this->blockPayments !== null || $this->invoiceById !== null) {
            $invoice = $this->invoiceById[$invoiceId] ?? null;

            return is_array($invoice) === true ? $invoice : null;
        }

        return $this->client->getInvoice($connection, $invoiceId);
    }

    /**
     * The payment link attributes, with the token this invoice synced kept beside the tokens
     * of the other invoices the payment still pays (see PaymentTokens).
     *
     * @param array<string, mixed>      $attributes
     * @param array<string, mixed>|null $legacy
     * @param array<string, mixed>      $remote     the QuickBooks payment
     *
     * @return array<string, mixed>
     */
    private function withInvoiceToken(
        SyncLedger $ledger,
        array $attributes,
        string $invoiceUuid,
        ?array $legacy,
        array $remote,
    ): array {
        $current = $ledger->link(
            (string) $attributes['company_uuid'],
            (string) $attributes['realm_id'],
            'payment',
            (string) $attributes['local_uuid']
        ) ?? $legacy;
        $token = (string) $attributes['sync_token'];
        $stale = $this->invoicesNoLongerPaid($ledger, $attributes, $current, $remote);

        return array_merge($attributes, [
            PaymentTokens::COLUMN => PaymentTokens::with($current, $invoiceUuid, $token, $stale),
        ]);
    }

    /**
     * The invoices whose token the payment link keeps although the payment no longer pays them:
     * their linked QuickBooks invoice is not in the payment's lines. An invoice whose link this
     * run did not load is kept, and so is every one when the payment lists no invoice.
     *
     * @param array<string, mixed>      $attributes
     * @param array<string, mixed>|null $current
     * @param array<string, mixed>      $remote
     *
     * @return array<int, string>
     */
    private function invoicesNoLongerPaid(SyncLedger $ledger, array $attributes, ?array $current, array $remote): array
    {
        $paid = [];
        foreach (is_array($remote['Line'] ?? null) === true ? $remote['Line'] : [] as $line) {
            foreach (is_array($line['LinkedTxn'] ?? null) === true ? $line['LinkedTxn'] : [] as $txn) {
                $invoice = is_array($txn) === true && in_array((string) ($txn['TxnType'] ?? ''), ['', 'Invoice'], true);
                if ($invoice === true) {
                    $paid[(string) ($txn['TxnId'] ?? '')] = true;
                }
            }
        }
        $company = (string) $attributes['company_uuid'];
        $realm   = (string) $attributes['realm_id'];
        $stale   = [];
        foreach (array_keys(PaymentTokens::all($current)) as $uuid) {
            $link = $ledger->link($company, $realm, 'invoice', $uuid);
            if ($paid !== [] && is_array($link) === true && isset($paid[(string) ($link['qbo_id'] ?? '')]) === false) {
                $stale[] = $uuid;
            }
        }

        return $stale;
    }

    /**
     * @param array<string, mixed>|null $remoteInvoice
     *
     * @return array<string, mixed>|null
     */
    private function cachedRemoteInvoice(?array $remoteInvoice, string $invoiceId): ?array
    {
        if ($remoteInvoice !== null || $invoiceId === '' || $this->invoiceById === null) {
            return $remoteInvoice;
        }
        $cached = $this->invoiceById[$invoiceId] ?? null;

        return is_array($cached) === true ? $cached : $remoteInvoice;
    }

    /**
     * Integer minor units applied to this invoice, across every payment.
     * One unknown amount makes the sum unknown so a partial total is not stored.
     *
     * @param array<int, array<string, mixed>> $payments
     */
    private function summedOnInvoice(array $payments, string $invoiceId, string $currency = ''): ?int
    {
        if ($payments === []) {
            return null;
        }

        $sum = 0;
        foreach ($payments as $remote) {
            $amount = $this->amountOnInvoice($remote, $invoiceId, $currency);
            if ($amount === null) {
                return null;
            }
            $sum += $amount;
        }

        return $sum;
    }

    /**
     * A payment can apply to several invoices. The line linked to this invoice
     * is the amount that belongs here. TotalAmt is only a fallback when QuickBooks
     * did not send lines.
     *
     * @param array<string, mixed> $remote
     */
    private function amountOnInvoice(array $remote, string $invoiceId, string $currency = ''): ?int
    {
        $applied = $this->linkedLineCents($remote, $invoiceId, $currency);
        if ($applied !== null) {
            return $applied;
        }
        $coversOthers = $this->paymentCoversOtherInvoices($remote, $invoiceId);
        if ($coversOthers === true || array_key_exists('TotalAmt', $remote) === false) {
            return null;
        }

        return $this->majorUnits($remote['TotalAmt'], $currency);
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function linkedLineCents(array $remote, string $invoiceId, string $currency = ''): ?int
    {
        $lines = $remote['Line'] ?? null;
        if (is_array($lines) === false || $invoiceId === '') {
            return null;
        }

        $sum   = 0;
        $found = false;
        foreach ($lines as $line) {
            if (is_array($line) === false || $this->lineLinksInvoice($line, $invoiceId) === false) {
                continue;
            }
            $found = true;
            $sum += $this->majorUnits($line['Amount'] ?? 0, $currency);
        }

        return $found === true ? $sum : null;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function lineLinksInvoice(array $line, string $invoiceId): bool
    {
        $txns = $line['LinkedTxn'] ?? [];
        if (is_array($txns) === false) {
            return false;
        }

        foreach ($txns as $txn) {
            if (is_array($txn) === false) {
                continue;
            }
            $type = (string) ($txn['TxnType'] ?? '');
            if ($type !== '' && $type !== 'Invoice') {
                continue;
            }
            if ((string) ($txn['TxnId'] ?? '') === $invoiceId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ledger sets paid_at only when an invoice is paid in full (Invoice::markAsPaid), so a
     * payment read from QuickBooks that leaves money owing does not give it a paid date.
     */
    private function clearQuickBooksPaidAt(SyncLedger $ledger, string $uuid): void
    {
        if (empty($ledger->invoices[$uuid]['payment_from_quickbooks']) === false) {
            $ledger->invoices[$uuid]['paid_at'] = '';
        }
    }

    /**
     * An invoice an earlier version left part-paid because a QuickBooks discount made the amount
     * paid smaller than its total. The amount already matches, so only the status changes.
     *
     * @param array<string, mixed>|null $remoteInvoice
     */
    private function markSettledPaid(SyncLedger $ledger, string $uuid, ?array $remoteInvoice): ?string
    {
        $partial  = (string) ($ledger->invoices[$uuid]['status'] ?? '') === 'partial';
        $currency = (string) ($ledger->invoices[$uuid]['currency'] ?? '');
        if ($partial === false || $this->remoteSettled($remoteInvoice, $currency) === false) {
            return null;
        }
        $ledger->invoices[$uuid]['status']                  = 'paid';
        $ledger->invoices[$uuid]['payment_from_quickbooks'] = true;

        return 'updated';
    }

    /**
     * QuickBooks shows the invoice as fully paid: a balance of zero on a non-zero total.
     *
     * @param array<string, mixed>|null $remoteInvoice
     */
    private function remoteSettled(?array $remoteInvoice, string $currency): bool
    {
        if ($remoteInvoice === null) {
            return false;
        }
        $total   = $remoteInvoice['TotalAmt'] ?? null;
        $balance = $remoteInvoice['Balance'] ?? null;
        if ($total === null || $total === '' || $balance === null || $balance === '') {
            return false;
        }

        return $this->majorUnits($total, $currency) > 0 && $this->majorUnits($balance, $currency) === 0;
    }

    /**
     * Customer and currency come back only when QuickBooks is the source.
     * A currency that is not the home currency is left alone; the other fields still copy.
     * An empty value does not clear Fleetbase.
     *
     * @param array<string, mixed> $remote
     */
    private function copyInvoiceCustomerAndCurrency(SyncLedger $ledger, string $uuid, array $remote): bool
    {
        $changed        = false;
        $company        = (string) ($ledger->invoices[$uuid]['company_uuid'] ?? '');
        $connection     = $ledger->connections[$company] ?? null;
        $realm          = is_array($connection) === true ? (string) ($connection['realm_id'] ?? '') : '';
        $remoteCustomer = $this->remoteInvoiceCustomerId($remote);
        if ($remoteCustomer !== '' && $realm !== '') {
            $localCustomer = $this->remoteInvoiceLocalCustomer($ledger, $realm, $remoteCustomer);
            if ($localCustomer === '') {
                $this->rowNotes['invoice:' . $uuid] ??= Translation::encode(
                    'quickbooks.messages.invoice-customer-not-linked',
                    ['id' => $remoteCustomer]
                );
            } elseif ($localCustomer !== (string) ($ledger->invoices[$uuid]['customer_uuid'] ?? '')) {
                $ledger->invoices[$uuid]['customer_uuid'] = $localCustomer;
                $changed                                  = true;
            }
        }
        $currency = $this->remoteInvoiceCurrency($remote);
        $known    = $currency !== '' && is_array($connection) === true;
        if ($known === true && $this->invoiceCurrencyError($ledger, $connection, $currency) === null) {
            $current = strtoupper(trim((string) ($ledger->invoices[$uuid]['currency'] ?? '')));
            if ($current !== $currency) {
                $ledger->invoices[$uuid]['currency'] = $currency;
                $changed                             = true;
            }
        }

        return $changed;
    }

    /**
     * The Fleetbase customer linked to a QuickBooks customer. A link this run did not load is
     * read, but not added to the ledger: the row already exists and would be written again.
     */
    private function remoteInvoiceLocalCustomer(SyncLedger $ledger, string $realm, string $remoteCustomer): string
    {
        $link = $ledger->linkForRemote($realm, 'Customer', $remoteCustomer);
        if ($link === null && $ledger->customerLinkReader !== null) {
            $link = ($ledger->customerLinkReader)($realm, $remoteCustomer);
        }

        $local = is_array($link) === true ? trim((string) ($link['local_uuid'] ?? '')) : '';
        // A deleted Fleetbase customer no longer stands for its QuickBooks customer.
        if ($local !== '' && empty($ledger->customers[$local]['deleted_at']) === false) {
            return '';
        }

        return $local;
    }
}
