<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Services\SyncEngine;
use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\ContentHash;
use Fleetbase\Quickbooks\Support\InvoiceMapper;

/**
 * SyncEngine's invoice comparison and line tracking: whether both sides hold the same content,
 * and which QuickBooks line each Fleetbase line is. Split from SyncsInvoices to keep each file readable.
 *
 * @phpstan-import-type RemovedLine from SyncEngine
 * @phpstan-import-type StoredLines from SyncEngine
 */
trait ComparesInvoices
{
    /**
     * An invoice currency that differs from the home currency is accepted only when
     * QuickBooks multi-currency is enabled.
     *
     * @param array<string, mixed> $connection
     */
    private function invoiceCurrencyError(SyncLedger $ledger, array $connection, string $currency): ?string
    {
        $error = $this->currencyError($ledger, $connection, $currency, 'quickbooks.messages.invoice-currency-mismatch');
        if ($error === null || $this->client->multiCurrencyEnabled($connection) === true) {
            return null;
        }

        return $error;
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $remote
     */
    private function fleetbaseDocNumberDiffers(array $invoice, array $remote, string $reference): bool
    {
        if ($reference === 'quickbooks') {
            return false;
        }

        $local = trim((string) ($invoice['number'] ?? ''));
        if ($local === '') {
            return false;
        }

        return $local !== trim((string) ($remote['DocNumber'] ?? ''));
    }

    /**
     * The same DocNumber on another customer's invoice is a different invoice.
     *
     * @param array<string, mixed> $remote
     */
    private function invoiceCustomerMatches(array $remote, string $customerId): bool
    {
        $customerId = trim($customerId);
        if ($customerId === '') {
            return false;
        }

        $remoteCustomer = $this->remoteInvoiceCustomerId($remote);

        return $remoteCustomer !== '' && $remoteCustomer === $customerId;
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function remoteInvoiceCustomerId(array $remote): string
    {
        $ref = $remote['CustomerRef'] ?? null;
        if (is_array($ref) === true) {
            return trim((string) ($ref['value'] ?? ''));
        }

        return trim((string) $ref);
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function remoteInvoiceCurrency(array $remote): string
    {
        $ref = $remote['CurrencyRef'] ?? null;
        if (is_array($ref) === true) {
            return strtoupper(trim((string) ($ref['value'] ?? '')));
        }

        return strtoupper(trim((string) $ref));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $remote
     */
    private function keepFleetbaseDocNumber(array &$payload, array $invoice, array $remote, string $reference): void
    {
        if ($this->fleetbaseDocNumberDiffers($invoice, $remote, $reference) === false) {
            return;
        }

        $payload['DocNumber'] = trim((string) ($invoice['number'] ?? ''));
    }

    /**
     * Whether the invoice and its QuickBooks invoice hold the same content. $compare says what
     * counts: pushClears compares emptied fields, currency compares the currencies, and linesOnly
     * compares only the lines Fleetbase sends (QuickBooks works out their tax).
     *
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $remote
     * @param array{
     *     remoteCents: int, localCents: int, reference: string, pushClears: bool, customerRef: string,
     *     currency: bool, linesOnly: bool
     * } $compare
     * @param StoredLines $lines stored QuickBooks lines
     */
    private function invoiceHashesMatch(array $invoice, array $remote, array $compare, array $lines = []): bool
    {
        if ($this->fleetbaseDocNumberDiffers($invoice, $remote, $compare['reference']) === true) {
            return false;
        }

        $localItems      = is_array($invoice['items'] ?? null) === true ? $invoice['items'] : [];
        $ownTax          = ($lines['fleetbase_tax'] ?? false) === true;
        $taxes           = $ownTax === true ? $this->ownTaxCompared($invoice, $localItems, $remote) : null;
        $totals          = $this->comparedInvoiceTotals($invoice, $localItems, $remote, $compare, $lines, $taxes);
        [$local, $other] = $this->invoiceHashFields($invoice, $remote, $compare, $totals);

        $codes          = $lines['codes'] ?? [];
        $mapped         = $this->invoiceMapper($invoice)->fromQuickBooks($totals['compared'], $localItems, $codes);
        $lineTax        = $compare['linesOnly'] === false && $ownTax === false;
        $local['items'] = $this->hashableInvoiceItems($localItems, $lineTax);
        $other['items'] = $this->hashableInvoiceItems($mapped['items'], $lineTax);
        if ($taxes !== null) {
            // QuickBooks and Ledger round line taxes differently, so only the invoice tax is compared.
            $local['tax'] = $taxes['local'];
            $other['tax'] = $taxes['remote'];
        } elseif ($compare['linesOnly'] === false) {
            $local['tax'] = (int) ($invoice['tax'] ?? 0);
            $other['tax'] = $mapped['tax'];
        }

        return ContentHash::of($local) === ContentHash::of($other);
    }

    /**
     * The totals compared, and the QuickBooks invoice whose lines are compared.
     *
     * @param array<string, mixed> $invoice
     * @param array<int, mixed>    $localItems
     * @param array<string, mixed> $remote
     * @param array{
     *     remoteCents: int, localCents: int, reference: string, pushClears: bool, customerRef: string,
     *     currency: bool, linesOnly: bool
     * } $compare
     * @param StoredLines                                        $lines
     * @param array{local: int, remote: int, in_total: int}|null $taxes
     *
     * @return array{local: int, remote: int, compared: array<string, mixed>}
     */
    private function comparedInvoiceTotals(
        array $invoice,
        array $localItems,
        array $remote,
        array $compare,
        array $lines,
        ?array $taxes,
    ): array {
        $localCents  = $compare['localCents'];
        $remoteCents = $compare['remoteCents'];
        $compared    = $remote;
        if ($taxes !== null && $compare['linesOnly'] === false) {
            // Fleetbase's tax is compared on its own, so the totals are compared before tax.
            $localCents -= $taxes['local'];
            $remoteCents -= $taxes['in_total'];
        }
        if ($compare['linesOnly'] === true) {
            // QuickBooks works out the tax of the lines Fleetbase sends, so only the lines are compared.
            $localCents = array_sum(array_map(
                static fn (mixed $item): int => is_array($item) === true ? (int) ($item['amount'] ?? 0) : 0,
                $localItems
            ));
            $removed     = $lines['removed'] ?? [];
            $mapped      = $lines['mapped'] ?? false;
            $limited     = $this->invoiceMapper($invoice)
                ->withoutUnmatchedSalesLines($localItems, $remote, $removed, $mapped);
            $compared    = $limited['remote'];
            $remoteCents -= $limited['omitted_cents'];
            // Fleetbase sends the lines, so a discount or the tax QuickBooks adds is not a change to
            // send: an update keeps it and the totals would differ again on every sync.
            $remoteCents -= $this->invoiceMapper($invoice)->quickbooksAdjustmentCents($remote);
        }

        return ['local' => $localCents, 'remote' => $remoteCents, 'compared' => $compared];
    }

    /**
     * The totals, dates, note, customer and currency of both sides, as compared.
     *
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $remote
     * @param array{
     *     remoteCents: int, localCents: int, reference: string, pushClears: bool, customerRef: string,
     *     currency: bool, linesOnly: bool
     * } $compare
     * @param array{local: int, remote: int, compared: array<string, mixed>} $totals
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function invoiceHashFields(array $invoice, array $remote, array $compare, array $totals): array
    {
        $pushClears = $compare['pushClears'];
        $local      = ['total' => $totals['local']];
        $other      = ['total' => $totals['remote']];
        foreach (['date' => 'TxnDate', 'due_date' => 'DueDate'] as $localKey => $remoteKey) {
            $value = (string) ($invoice[$localKey] ?? '');
            if ($value === '' && $pushClears === false) {
                continue;
            }
            $local[$localKey] = $value;
            $other[$localKey] = (string) ($remote[$remoteKey] ?? '');
        }
        if ($pushClears === true || array_key_exists('PrivateNote', $remote) === true) {
            $local['notes'] = InvoiceMapper::privateNote((string) ($invoice['notes'] ?? ''));
            $other['notes'] = (string) ($remote['PrivateNote'] ?? '');
        }
        $remoteCustomer = $pushClears === true ? $this->remoteInvoiceCustomerId($remote) : '';
        if ($remoteCustomer !== '') {
            $local['customer'] = trim($compare['customerRef']);
            $other['customer'] = $remoteCustomer;
        }
        $localCurrency  = $compare['currency'] === true ? strtoupper(trim((string) ($invoice['currency'] ?? ''))) : '';
        $remoteCurrency = $compare['currency'] === true ? $this->remoteInvoiceCurrency($remote) : '';
        if ($localCurrency !== '' && $remoteCurrency !== '') {
            $local['currency'] = $localCurrency;
            $other['currency'] = $remoteCurrency;
        }

        return [$local, $other];
    }

    /**
     * Whether Fleetbase changed what it sends of an invoice QuickBooks did not change since the
     * last sync: the lines and amounts, dates, notes or customer. The tax and discounts QuickBooks
     * works out on those lines are not a Fleetbase change; QuickBooks decides them, unless Fleetbase
     * works out the tax ($ownTax), whose difference is a change to send. An emptied note
     * is a change. An empty date or due date is not: QuickBooks always holds both.
     *
     * @param array<string, mixed>                                                             $invoice
     * @param array<string, mixed>                                                             $remote
     * @param array{removed: array<int, RemovedLine>, uuids: array<int, string>, mapped: bool} $stored
     */
    private function fleetbaseEditedInvoice(
        array $invoice,
        array $remote,
        string $reference,
        string $customerRef,
        array $stored,
        bool $ownTax = false,
    ): bool {
        $remoteCustomer = $this->remoteInvoiceCustomerId($remote);
        if ($remoteCustomer !== '' && $remoteCustomer !== trim($customerRef)) {
            return true;
        }
        foreach (['date' => 'TxnDate', 'due_date' => 'DueDate'] as $local => $remoteKey) {
            if (trim((string) ($invoice[$local] ?? '')) === '') {
                $invoice[$local] = (string) ($remote[$remoteKey] ?? '');
            }
        }
        $remoteCents = $this->majorUnits($remote['TotalAmt'] ?? 0, (string) ($invoice['currency'] ?? ''));
        $lines       = ['removed' => $stored['removed'], 'mapped' => $stored['mapped'], 'fleetbase_tax' => $ownTax];
        $localCents  = (int) ($invoice['total'] ?? 0);

        $compare = [
            'remoteCents' => $remoteCents,
            'localCents'  => $localCents,
            'reference'   => $reference,
            'pushClears'  => true,
            'customerRef' => $customerRef,
            'currency'    => false,
            'linesOnly'   => true,
        ];

        return $this->invoiceHashesMatch($invoice, $remote, $compare, $lines) === false;
    }

    /**
     * The content of each line, as InvoiceMapper::item builds it. The line id, item uuid and stored
     * line are how a sync pairs a line, so they are left out. The line tax is left out too when
     * QuickBooks works it out from the lines Fleetbase sends ($withTax false).
     *
     * @param array<int, mixed> $items
     *
     * @return array<int, array<string, mixed>>
     */
    private function hashableInvoiceItems(array $items, bool $withTax = true): array
    {
        $clean = [];
        foreach ($items as $item) {
            if (is_array($item) === false) {
                continue;
            }
            $line = InvoiceMapper::item(
                (string) ($item['description'] ?? ''),
                (int) ($item['quantity'] ?? 1),
                (int) ($item['unit_price'] ?? 0),
                (int) ($item['amount'] ?? 0),
                InvoiceMapper::taxRate($item['tax_rate'] ?? 0),
                (int) ($item['tax_amount'] ?? 0)
            );
            if ($withTax === false) {
                unset($line['tax_rate'], $line['tax_amount']);
            }
            $clean[] = $line;
        }

        return $clean;
    }

    /**
     * Remember the QuickBooks ids copied onto the Fleetbase lines so the next sync
     * pairs those lines and does not treat leftover remote sales lines as local edits.
     *
     * @param array<string, mixed> $payload
     */
    private function rememberPushedLineIds(SyncLedger $ledger, string $uuid, array $payload): void
    {
        $items = $ledger->invoices[$uuid]['items'] ?? null;
        $lines = is_array($payload['Line'] ?? null) === true ? $payload['Line'] : [];
        if (is_array($items) === false) {
            return;
        }
        $sales = [];
        foreach ($lines as $line) {
            if (is_array($line) === false || (string) ($line['DetailType'] ?? '') !== 'SalesItemLineDetail') {
                continue;
            }
            $sales[] = trim((string) ($line['Id'] ?? ''));
        }
        foreach ($items as $index => $item) {
            if (is_array($item) === false || isset($sales[$index]) === false || $sales[$index] === '') {
                continue;
            }
            $ledger->invoices[$uuid]['items'][$index]['qbo_line_id'] = $sales[$index];
        }
    }

    /**
     * Copies the QuickBooks line ids stored for this invoice's link onto its items, so they pair
     * exactly. Returns the lines whose Fleetbase item was deleted (no live item holds that id),
     * the uuids of those deleted items, and whether the invoice has stored ids at all. Ids stored
     * for another company or invoice are ignored.
     *
     * @param array<string, mixed> $connection
     *
     * @return array{removed: array<int, RemovedLine>, uuids: array<int, string>, mapped: bool}
     */
    private function storedLineIds(SyncLedger $ledger, array $connection, string $uuid, bool $voided = false): array
    {
        $realm     = (string) $connection['realm_id'];
        $link      = $ledger->link((string) $connection['company_uuid'], $realm, 'invoice', $uuid);
        $invoiceId = is_array($link) === true ? trim((string) ($link['qbo_id'] ?? '')) : '';
        $items     = $ledger->invoices[$uuid]['items'] ?? null;
        if ($voided === true || $invoiceId === '' || is_array($items) === false) {
            return ['removed' => [], 'uuids' => [], 'mapped' => false];
        }
        $held = [];
        foreach ($items as $index => $item) {
            if (is_array($item) === false) {
                continue;
            }
            $lineId = $this->storedLineId($item['qbo_line'] ?? null, $realm, $invoiceId);
            if ($lineId !== '') {
                $ledger->invoices[$uuid]['items'][$index]['qbo_line_id'] = $lineId;
            }
            $current = trim((string) ($ledger->invoices[$uuid]['items'][$index]['qbo_line_id'] ?? ''));
            if ($current !== '') {
                $held[$current] = true;
            }
        }
        $removed = [];
        $uuids   = [];
        foreach ($this->removedLines($ledger, $uuid) as $line) {
            $lineId = $this->storedLineId($line, $realm, $invoiceId);
            if ($lineId === '' || isset($held[$lineId]) === true) {
                continue;
            }
            $removed[] = [
                'id'          => $lineId,
                'description' => (string) ($line['description'] ?? ''),
                'amount'      => (int) ($line['amount'] ?? 0),
            ];
            $uuids[]   = (string) ($line['uuid'] ?? '');
        }

        return [
            'removed' => $removed,
            'uuids'   => array_values(array_filter(array_unique($uuids))),
            'mapped'  => $held !== [] || $removed !== [],
        ];
    }

    private function storedLineId(mixed $line, string $realm, string $invoiceId): string
    {
        if (is_array($line) === false) {
            return '';
        }
        if ((string) ($line['realm'] ?? '') !== $realm || (string) ($line['invoice'] ?? '') !== $invoiceId) {
            return '';
        }

        return trim((string) ($line['id'] ?? ''));
    }

    /**
     * After QuickBooks accepted a write, each Fleetbase item keeps the line id it was sent with.
     * Items sent without one take the ids QuickBooks returned that were not sent, in order.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     * @param array<int, string>   $cleared
     */
    private function recordPushedLines(
        SyncLedger $ledger,
        string $uuid,
        array $connection,
        string $invoiceId,
        array $payload,
        mixed $response,
        array $cleared,
    ): void {
        $sent  = $this->invoices->salesLineIds($payload);
        $known = array_flip(array_filter($sent, static fn (string $lineId): bool => $lineId !== ''));
        $fresh = [];
        foreach (is_array($response) === true ? $this->invoices->salesLineIds($response) : [] as $lineId) {
            if ($lineId !== '' && isset($known[$lineId]) === false) {
                $fresh[] = $lineId;
            }
        }
        $ids = [];
        foreach ($sent as $index => $lineId) {
            $ids[$index] = $lineId !== '' ? $lineId : (string) (array_shift($fresh) ?? '');
        }
        $this->recordLineMap($ledger, $uuid, $connection, $invoiceId, $ids, $cleared);
    }

    /**
     * QuickBooks lines replaced the Fleetbase lines, so the new items take those lines' ids.
     *
     * @param array<string, mixed> $remote
     */
    private function recordReplacedLines(SyncLedger $ledger, string $uuid, array $remote, string $realmId): void
    {
        $invoiceId = trim((string) ($remote['Id'] ?? ''));
        if ($realmId === '' || $invoiceId === '') {
            return;
        }
        $cleared = [];
        foreach ($this->removedLines($ledger, $uuid) as $line) {
            $cleared[] = is_array($line) === true ? (string) ($line['uuid'] ?? '') : '';
        }
        $ids     = $this->invoices->salesLineIds($remote);
        $cleared = array_values(array_filter($cleared));
        $this->recordLineMap($ledger, $uuid, ['realm_id' => $realmId], $invoiceId, $ids, $cleared);
    }

    /**
     * @return array<int, mixed>
     */
    private function removedLines(SyncLedger $ledger, string $uuid): array
    {
        $lines = $ledger->invoices[$uuid]['removed_lines'] ?? null;

        return is_array($lines) === true ? $lines : [];
    }

    /**
     * Marks which QuickBooks line each item is, for the directory to store. Set only once the
     * invoice matched or a write succeeded.
     *
     * @param array<string, mixed> $connection
     * @param array<int, string>   $ids        line id by item position
     * @param array<int, string>   $cleared    deleted items whose stored line is gone
     */
    private function recordLineMap(
        SyncLedger $ledger,
        string $uuid,
        array $connection,
        string $invoiceId,
        array $ids,
        array $cleared,
    ): void {
        $items = $ledger->invoices[$uuid]['items'] ?? null;
        if ($invoiceId === '' || is_array($items) === false) {
            return;
        }
        $position = 0;
        foreach ($items as $index => $item) {
            if (is_array($item) === false) {
                continue;
            }
            $lineId = $ids[$position] ?? '';
            $position++;
            if ($lineId !== '') {
                $ledger->invoices[$uuid]['items'][$index]['qbo_line_id'] = $lineId;
            }
        }
        $ledger->invoices[$uuid]['line_map'] = ['realm' => (string) $connection['realm_id'], 'invoice' => $invoiceId];
        if ($cleared !== []) {
            $ledger->invoices[$uuid]['cleared_lines'] = $cleared;
        }
    }

    /**
     * Whether the Fleetbase invoice and the QuickBooks invoice hold the same lines, amounts,
     * dates, note and customer. The tax and discounts QuickBooks works out are not compared.
     *
     * @param array<string, mixed>                                                             $connection
     * @param array<string, mixed>                                                             $remote
     * @param array<string, mixed>                                                             $settings
     * @param array{removed: array<int, RemovedLine>, uuids: array<int, string>, mapped: bool} $stored
     */
    private function invoiceInSync(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $remote,
        array $settings,
        array $stored,
    ): bool {
        $invoice = $ledger->invoices[$uuid] ?? null;
        if (is_array($invoice) === false) {
            return true;
        }
        $customer  = $ledger->link(
            (string) $connection['company_uuid'],
            (string) $connection['realm_id'],
            'customer',
            (string) ($invoice['customer_uuid'] ?? '')
        );
        $owner     = (string) ($invoice['customer_uuid'] ?? '');
        $ref       = is_array($customer) === true
            ? (string) ($customer['qbo_id'] ?? '')
            : $this->unloadedCustomerRef($ledger, $connection, $remote, $owner);
        $company   = (string) $connection['company_uuid'];
        $link      = $ledger->link($company, (string) $connection['realm_id'], 'invoice', $uuid);
        $ref       = $this->keptCustomerRef($ledger, $connection, $settings, $ref, $remote, $owner, $link);
        $reference = (string) ($settings['invoice_reference'] ?? 'fleetbase');
        $ownTax    = $this->comparesOwnTax($connection, $this->ownsTax($settings, $link));

        return $this->fleetbaseEditedInvoice($invoice, $remote, $reference, $ref, $stored, $ownTax) === false;
    }
}
