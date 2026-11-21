<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\Amounts;
use Fleetbase\Quickbooks\Support\FleetbaseTax;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\InvoiceTax;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\Translation;

/**
 * SyncEngine's tax handling: the tax plan sent with an invoice, QuickBooks tax codes and rates,
 * and Fleetbase-owned tax (tax_source fleetbase). It shares the engine's state, so it lives
 * here only to keep SyncEngine.php readable.
 *
 * @phpstan-import-type Settings from InvoiceTax as InvoiceTaxSettings
 */
trait SyncsTax
{
    /**
     * The tax code of each line and the invoice rate (InvoiceTax::plan), from the QuickBooks sales
     * tax settings, customer and the product of each line. A read that fails fails the invoice, so
     * its lines are not sent untaxed by mistake.
     *
     * @param array<string, mixed> $connection
     * @param array<int, string>   $lineItems  the product of each line, by position (else $itemId)
     *
     * @return array{lines?: array<int, string|null>, rate?: string|null, global?: string|null}
     */
    private function invoiceTaxPlan(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        string $customerRef,
        string $itemId,
        array $lineItems = [],
    ): array {
        $settings = $this->salesTaxSettings($connection);
        $taxed    = $settings['mode'] !== InvoiceTax::OFF;
        $item     = $this->taxItem($ledger, $connection, $itemId, $taxed);
        if ($taxed === false) {
            return [];
        }
        $items = is_array($ledger->invoices[$uuid]['items'] ?? null) === true ? $ledger->invoices[$uuid]['items'] : [];
        $ids   = [];
        foreach (array_keys(array_values($items)) as $index) {
            $ids[$index] = $lineItems[$index] ?? $itemId;
        }
        $known = $item === null ? [] : [$itemId => $item];
        foreach (array_unique($ids) as $id) {
            if (isset($known[$id]) === false) {
                $read = $this->taxItem($ledger, $connection, $id, true, false);
                if ($read !== null) {
                    $known[$id] = $read;
                }
            }
        }

        return InvoiceTax::plan(
            $settings,
            $this->taxCustomer($connection, $customerRef),
            $known,
            $ids
        );
    }

    /**
     * Whether an invoice whose tax QuickBooks works out can be sent. Outside the US QuickBooks
     * refuses a line with no sales tax code (checked in a Canadian sandbox company), so such an
     * invoice is stopped with lastError naming the line. In the US a missing rate is only noted.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $connection
     */
    private function quickBooksTaxSendable(string $uuid, array $payload, array $connection): bool
    {
        if ($this->salesTaxSettings($connection)['mode'] !== InvoiceTax::GLOBAL) {
            $this->noteMissingTaxRate($uuid, $payload, $connection);

            return true;
        }
        foreach (is_array($payload['Line'] ?? null) === true ? $payload['Line'] : [] as $line) {
            $detail = is_array($line) === true ? ($line['SalesItemLineDetail'] ?? null) : null;
            if (is_array($detail) === true && trim((string) ($detail['TaxCodeRef']['value'] ?? '')) === '') {
                $name            = (string) ($line['Description'] ?? ($detail['ItemRef']['name'] ?? ''));
                $this->lastError = Translation::encode('quickbooks.messages.invoice-tax-no-code', ['line' => $name]);

                return false;
            }
        }

        return true;
    }

    /**
     * Lines coded taxable on an invoice with no sales tax rate get no tax from QuickBooks, which
     * Activity says. Automated sales tax chooses the rate itself.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $connection
     */
    private function noteMissingTaxRate(string $uuid, array $payload, array $connection): void
    {
        $rated = isset($payload['TxnTaxDetail']['TxnTaxCodeRef']['value']) === true;
        if ($rated === true || $this->salesTaxSettings($connection)['automated'] === true) {
            return;
        }
        foreach (is_array($payload['Line'] ?? null) === true ? $payload['Line'] : [] as $line) {
            if (is_array($line) === true && ($line['SalesItemLineDetail']['TaxCodeRef']['value'] ?? null) === 'TAX') {
                $this->rowNotes['invoice:' . $uuid] = Translation::encode('quickbooks.messages.invoice-no-tax-rate');

                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return InvoiceTaxSettings
     */
    private function salesTaxSettings(array $connection): array
    {
        $companyUuid = (string) $connection['company_uuid'];
        $cached      = $this->salesTaxByCompany[$companyUuid] ?? null;
        if ($cached instanceof QuickBooksException) {
            throw $cached;
        }
        if (is_array($cached) === true) {
            return $cached;
        }
        try {
            $settings = $this->client->salesTax($connection);
        } catch (QuickBooksException $exception) {
            $lasting = $exception->isUnauthorized() === false && $exception->isRateLimit() === false;
            if ($this->salesTaxByCompany !== null && $lasting === true) {
                $this->salesTaxByCompany[$companyUuid] = $exception;
            }

            throw $exception;
        }
        if ($this->salesTaxByCompany !== null) {
            $this->salesTaxByCompany[$companyUuid] = $settings;
        }

        return $settings;
    }

    /**
     * A QuickBooks item invoice lines use, read once per block, or taken from the products this run
     * read or wrote. The service item's income account is kept on the connection for the
     * connection card. Without sales tax ($required false) a failed read only leaves the card as
     * it was.
     *
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    private function taxItem(
        SyncLedger $ledger,
        array $connection,
        string $itemId,
        bool $required,
        bool $service = true,
    ): ?array {
        $companyUuid = (string) $connection['company_uuid'];
        if ($this->taxItems !== null && array_key_exists($itemId, $this->taxItems[$companyUuid] ?? []) === true) {
            return $this->taxItems[$companyUuid][$itemId];
        }
        $item = $this->itemRows[$itemId] ?? null;
        try {
            $item ??= $this->client->item($connection, $itemId);
        } catch (QuickBooksException $exception) {
            if ($required === true || $exception->isUnauthorized() === true || $exception->isRateLimit() === true) {
                throw $exception;
            }
            $item = null;
        }
        if (is_array($item) === true) {
            $this->itemRows[$itemId] = $item;
        }
        if ($this->taxItems !== null) {
            $this->taxItems[$companyUuid][$itemId] = $item;
        }
        $account = is_array($item) === true && $service === true
            ? trim((string) ($item['IncomeAccountRef']['name'] ?? ''))
            : '';
        if ($account !== '' && isset($ledger->connections[$companyUuid]) === true) {
            $ledger->connections[$companyUuid]['default_item_account'] = $account;
        }

        return $item;
    }

    /**
     * The QuickBooks customer of an invoice, for its taxable flag and rate. A block reads its
     * customers in one query (prefetchTaxCustomers).
     *
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    private function taxCustomer(array $connection, string $customerRef): ?array
    {
        if ($this->taxCustomers !== null && array_key_exists($customerRef, $this->taxCustomers) === true) {
            $cached = $this->taxCustomers[$customerRef];
            if ($cached instanceof QuickBooksException) {
                throw $cached;
            }

            return $cached;
        }
        $customer = $this->client->getCustomer($connection, $customerRef);
        if ($this->taxCustomers !== null) {
            $this->taxCustomers[$customerRef] = $customer;
        }

        return $customer;
    }

    /**
     * When QuickBooks taxes invoices, read the QuickBooks customers of the block's invoices in one
     * query. A failed tax settings read is left for each invoice to fail with, and a customer the
     * query does not return for each invoice to read.
     *
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     */
    private function prefetchTaxCustomers(array $connection, SyncLedger $ledger, array $rows): void
    {
        try {
            $settings = $this->salesTaxSettings($connection);
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized() === true || $exception->isRateLimit() === true) {
                throw $exception;
            }

            return;
        }
        if ($settings['mode'] === InvoiceTax::OFF) {
            return;
        }
        $companyUuid = (string) $connection['company_uuid'];
        $realm       = (string) $connection['realm_id'];
        $ids         = [];
        foreach ($rows as $row) {
            $invoice      = $ledger->invoices[(string) ($row['local_uuid'] ?? '')] ?? null;
            $customerUuid = is_array($invoice) === true ? (string) ($invoice['customer_uuid'] ?? '') : '';
            $link         = $customerUuid === ''
                ? null
                : $ledger->link($companyUuid, $realm, 'customer', $customerUuid);
            $id           = is_array($link) === true ? trim((string) ($link['qbo_id'] ?? '')) : '';
            if ($id !== '') {
                $ids[$id] = $id;
            }
        }
        $loaded = $this->queryWhereIn(
            $connection,
            'Customer',
            'Id',
            array_values($ids),
            fn (string $id): ?array => $this->client->getCustomer($connection, $id)
        );
        // The query leaves out inactive customers, so a customer it omits is read by id later.
        foreach ($loaded['rows'] as $remote) {
            $id = trim((string) ($remote['Id'] ?? ''));
            if (isset($ids[$id]) === true) {
                $this->taxCustomers[$id] = $remote;
            }
        }
        foreach ($loaded['failed'] as $id => $error) {
            $this->taxCustomers[(string) $id] = $error;
        }
    }

    /**
     * Copy the tax QuickBooks worked out on the lines Fleetbase sent onto the Fleetbase invoice:
     * the tax, each line's share of it, and the total that follows. The status follows the new
     * total. Activity says when a line tax rate Fleetbase had changed. Returns whether anything
     * changed.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $remote
     */
    private function copyQuickBooksTax(SyncLedger $ledger, array $connection, string $uuid, array $remote): bool
    {
        $invoice = $ledger->invoices[$uuid] ?? null;
        if (is_array($invoice) === false) {
            return false;
        }
        $items    = is_array($invoice['items'] ?? null) === true ? $invoice['items'] : [];
        $codes    = $this->taxCodesFor($connection, $remote);
        $taxed    = $this->invoiceMapper($invoice)->quickBooksTax($remote, $items, $codes);
        $subtotal = 0;
        $changed  = false;
        $replaced = false;
        foreach ($taxed['items'] as $index => $item) {
            $subtotal += (int) ($item['amount'] ?? 0);
            $before    = is_array($items[$index] ?? null) === true ? $items[$index] : [];
            $rate      = InvoiceMapper::taxRate($before['tax_rate'] ?? 0);
            $changed   = $changed || $rate !== $item['tax_rate']
                || (int) ($before['tax_amount'] ?? 0) !== $item['tax_amount'];
            $replaced  = $replaced || ($rate !== '0.00' && $rate !== $item['tax_rate']);
        }
        $previous = (int) ($invoice['tax'] ?? 0);
        // The lines of a tax-inclusive invoice already hold the tax.
        $total = $taxed['inclusive'] === true ? $subtotal : $subtotal + $taxed['tax'];
        if ($changed === false && $previous === $taxed['tax'] && (int) ($invoice['total'] ?? 0) === $total) {
            return false;
        }
        foreach ($taxed['items'] as $index => $item) {
            $ledger->invoices[$uuid]['items'][$index]['tax_rate']   = $item['tax_rate'];
            $ledger->invoices[$uuid]['items'][$index]['tax_amount'] = $item['tax_amount'];
        }
        $ledger->invoices[$uuid]['tax']                        = $taxed['tax'];
        $ledger->invoices[$uuid]['total']                      = $total;
        $ledger->invoices[$uuid]['replace_from_quickbooks']    = true;
        $ledger->invoices[$uuid]['line_taxes_from_quickbooks'] = true;
        // An invoice marked paid with no amount recorded is paid in full, whatever its new total
        // (paymentAmountCents sends that total), so only a recorded amount moves the status.
        if ((int) ($invoice['amount_paid'] ?? 0) !== 0) {
            $this->applyPaymentStatus($ledger, $uuid);
        }
        $this->noteCopiedTax($uuid, $total < (int) ($invoice['total'] ?? 0), $replaced);

        return true;
    }

    /**
     * The sales tax rates of each QuickBooks tax code (InvoiceTax::codeRates), for sharing the tax
     * of an invoice outside the US over its lines. Only read when a line names a rate code (not
     * TAX or NON), so a US or untaxed invoice reads nothing. A lasting read failure leaves the
     * tax whole on the invoice, as before codes were read.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $remote
     *
     * @return array<string, array<int, string>>
     */
    private function taxCodesFor(array $connection, array $remote): array
    {
        if (InvoiceMapper::namesRateCodes($remote) === false) {
            return [];
        }
        try {
            return $this->salesTaxSettings($connection)['codes'] ?? [];
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized() === true || $exception->isRateLimit() === true) {
                throw $exception;
            }

            return [];
        }
    }

    /**
     * Whether the settings have Fleetbase work out invoice tax (tax_source fleetbase).
     *
     * @param array<string, mixed> $settings
     */
    private function fleetbaseWorksOutTax(array $settings): bool
    {
        return ($settings['tax_source'] ?? 'quickbooks') === self::FLEETBASE_TAX;
    }

    /**
     * Whether Fleetbase works out this invoice's tax: the settings say so and the invoice was first
     * sent to QuickBooks while they did (its link is marked). An older invoice keeps QuickBooks'
     * tax, so turning the setting on never changes the tax of invoices already in QuickBooks.
     *
     * @param array<string, mixed>      $settings
     * @param array<string, mixed>|null $link     the invoice link
     */
    private function ownsTax(array $settings, ?array $link): bool
    {
        return $this->fleetbaseWorksOutTax($settings) === true
            && is_array($link) === true
            && ($link[self::TAX_SOURCE] ?? null) === self::FLEETBASE_TAX;
    }

    /**
     * Whether an invoice whose tax Fleetbase works out ($owns) has its tax compared with
     * QuickBooks: not while sales tax is off in QuickBooks, which then holds none. A failed read
     * compares nothing; the invoice fails on it when sent.
     *
     * @param array<string, mixed> $connection
     */
    private function comparesOwnTax(array $connection, bool $owns): bool
    {
        if ($owns === false) {
            return false;
        }
        try {
            return $this->salesTaxSettings($connection)['mode'] !== InvoiceTax::OFF;
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized() === true || $exception->isRateLimit() === true) {
                throw $exception;
            }

            return false;
        }
    }

    /**
     * Whether QuickBooks holds another invoice tax than the Fleetbase lines. A tax-inclusive
     * QuickBooks invoice always differs: its sync fails and says why (syncInvoice).
     *
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $remote
     */
    private function ownTaxDiffers(array $invoice, array $remote): bool
    {
        if (InvoiceMapper::taxInclusive($remote) === true) {
            return true;
        }
        $items = is_array($invoice['items'] ?? null) === true ? $invoice['items'] : [];
        $tax   = $this->majorUnits($remote['TxnTaxDetail']['TotalTax'] ?? 0, (string) ($invoice['currency'] ?? ''));

        return FleetbaseTax::total($items) !== $tax;
    }

    /**
     * Put Fleetbase's tax on a payload whose lines are final (FleetbaseTax::apply). Null when the
     * invoice cannot be sent, with lastError saying why. With sales tax off in QuickBooks no tax
     * is sent, which Activity says. Outside the US the company's tax rates are read once.
     *
     * @param array<string, mixed>                                                             $connection
     * @param array<string, mixed>                                                             $payload
     * @param array{lines?: array<int, string|null>, rate?: string|null, global?: string|null} $plan
     * @param array<string, mixed>                                                             $remote     none when new
     *
     * @return array<string, mixed>|null
     */
    private function withFleetbaseTax(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $payload,
        array $plan,
        array $remote,
    ): ?array {
        $settings = $this->salesTaxSettings($connection);
        $items    = $ledger->invoices[$uuid]['items'] ?? [];
        $items    = is_array($items) === true ? $items : [];
        if ($settings['mode'] === InvoiceTax::OFF && FleetbaseTax::total($items) !== 0) {
            $this->rowNotes['invoice:' . $uuid] = Translation::encode('quickbooks.messages.invoice-tax-not-sent');
        }
        $rates   = $settings['mode'] === InvoiceTax::GLOBAL ? $this->client->taxRates($connection) : [];
        $applied = FleetbaseTax::apply($payload, $items, $settings, $plan['lines'] ?? [], $rates, $remote);
        if ($applied['error'] !== null) {
            $this->lastError = $applied['error'];

            return null;
        }

        return $applied['payload'];
    }

    /**
     * Mark an invoice link: Fleetbase works out this invoice's tax (ownsTax).
     *
     * @param array<string, mixed> $connection
     */
    private function markOwnTax(SyncLedger $ledger, array $connection, string $uuid): void
    {
        $ledger->putLink([
            'company_uuid'   => $connection['company_uuid'],
            'local_type'     => 'invoice',
            'local_uuid'     => $uuid,
            self::TAX_SOURCE => self::FLEETBASE_TAX,
        ]);
    }

    /**
     * After a write that sent Fleetbase's tax: the error when QuickBooks kept another tax, else
     * null. The link then remembers the QuickBooks SyncToken and the tax sent, so the invoice is
     * not sent again until one of them changes (stillRefused).
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload    what was sent
     * @param array<string, mixed> $written    what QuickBooks returned
     */
    private function refusedOwnTax(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $payload,
        array $written,
    ): ?string {
        $sent = $payload['TxnTaxDetail']['TotalTax'] ?? null;
        if (is_string($sent) === false) {
            return null;
        }
        $currency = (string) ($ledger->invoices[$uuid]['currency'] ?? '');
        $sentTax  = $this->majorUnits($sent, $currency);
        $keptTax  = $this->majorUnits($written['TxnTaxDetail']['TotalTax'] ?? 0, $currency);
        $refusal  = $sentTax === $keptTax ? null : (string) ($written['SyncToken'] ?? '') . '|' . $sentTax;
        $ledger->putLink([
            'company_uuid'    => $connection['company_uuid'],
            'local_type'      => 'invoice',
            'local_uuid'      => $uuid,
            self::REFUSED_TAX => $refusal,
        ]);

        return $refusal === null ? null : $this->refusedTaxError($sentTax, $keptTax, $currency);
    }

    /**
     * The error of a refusal (refusedOwnTax) that still stands: QuickBooks has not changed the
     * invoice since, and Fleetbase would send the same tax. Null otherwise.
     *
     * @param array<string, mixed>|null $link
     * @param array<string, mixed>      $invoice
     * @param array<string, mixed>      $remote
     */
    private function stillRefused(?array $link, array $invoice, array $remote): ?string
    {
        $stored   = is_array($link) === true ? (string) ($link[self::REFUSED_TAX] ?? '') : '';
        $items    = is_array($invoice['items'] ?? null) === true ? $invoice['items'] : [];
        $sentTax  = FleetbaseTax::total($items);
        $currency = (string) ($invoice['currency'] ?? '');
        if ($stored === '' || $stored !== (string) ($remote['SyncToken'] ?? '') . '|' . $sentTax) {
            return null;
        }
        $keptTax = $this->majorUnits($remote['TxnTaxDetail']['TotalTax'] ?? 0, $currency);

        return $this->refusedTaxError($sentTax, $keptTax, $currency);
    }

    private function refusedTaxError(int $sentTax, int $keptTax, string $currency): string
    {
        return Translation::encode('quickbooks.messages.invoice-tax-not-kept', [
            'sent' => Amounts::centsToDecimal($sentTax, $currency),
            'kept' => Amounts::centsToDecimal($keptTax, $currency),
        ]);
    }

    /**
     * A lower total, maybe below what was paid, is noted before any other note but a missing
     * sales tax rate, which is why there was no tax. Otherwise a Fleetbase line rate QuickBooks
     * replaced is noted.
     */
    private function noteCopiedTax(string $uuid, bool $lowered, bool $replaced): void
    {
        $noRate = Translation::encode('quickbooks.messages.invoice-no-tax-rate');
        if (($this->rowNotes['invoice:' . $uuid] ?? null) === $noRate) {
            return;
        }
        if ($lowered === true) {
            $this->rowNotes['invoice:' . $uuid] = Translation::encode('quickbooks.messages.invoice-tax-lowered');
        } elseif ($replaced === true) {
            $note                                 = 'quickbooks.messages.invoice-tax-from-quickbooks';
            $this->rowNotes['invoice:' . $uuid] ??= Translation::encode($note);
        }
    }

    /**
     * The invoice tax compared when Fleetbase works out the tax: the Fleetbase line taxes added up
     * (local), the QuickBooks invoice tax (remote) and the part of the QuickBooks total that is
     * tax (in_total). A tax-inclusive QuickBooks invoice has its tax inside the line amounts, so its
     * tax counts as the same and no tax is taken from its total.
     *
     * @param array<string, mixed>             $invoice
     * @param array<int, array<string, mixed>> $localItems
     * @param array<string, mixed>             $remote
     *
     * @return array{local: int, remote: int, in_total: int}
     */
    private function ownTaxCompared(array $invoice, array $localItems, array $remote): array
    {
        $local = FleetbaseTax::total($localItems);
        if (InvoiceMapper::taxInclusive($remote) === true) {
            return ['local' => $local, 'remote' => $local, 'in_total' => 0];
        }
        $remoteTax = $this->majorUnits($remote['TxnTaxDetail']['TotalTax'] ?? 0, (string) ($invoice['currency'] ?? ''));

        return ['local' => $local, 'remote' => $remoteTax, 'in_total' => $remoteTax];
    }

    /**
     * Whether a push copies back the tax QuickBooks worked out on the lines Fleetbase sent: in
     * two-way sync, after a create, and after an update unless QuickBooks lines are Primary (that
     * update already copied QuickBooks onto Fleetbase).
     *
     * @param array<string, mixed> $settings
     */
    private function copiesTaxAfterPush(array $settings, string $outcome): bool
    {
        $direction = $this->direction($settings, 'invoice');
        $conflict  = (string) ($settings['invoice_conflict'] ?? 'fleetbase');
        if ($this->copiesRemote($direction) === false || $this->pushesRemote($direction) === false) {
            return false;
        }

        return $outcome === 'created' || ($outcome === 'updated' && $conflict !== 'quickbooks');
    }
}
