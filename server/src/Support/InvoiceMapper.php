<?php

namespace Fleetbase\Quickbooks\Support;

class InvoiceMapper
{
    /**
     * The highest line tax rate a Ledger line holds (ledger_invoice_items.tax_rate is decimal(5,2)).
     */
    private const LINE_TAX_RATE_MAX = 999.99;

    /**
     * Characters a Ledger invoice line description holds (ledger_invoice_items.description).
     * QuickBooks allows 4000, so a longer description is cut to this on the way in.
     */
    public const LINE_DESCRIPTION_MAX = 191;

    /** QuickBooks keeps at most this many characters of an invoice's PrivateNote. */
    public const PRIVATE_NOTE_MAX = 4000;

    /**
     * The invoice note as it is sent. Compare the local note through this too, or a longer note
     * is sent again on every sync.
     */
    public static function privateNote(string $note): string
    {
        return mb_substr($note, 0, self::PRIVATE_NOTE_MAX);
    }

    public const PAYMENT_REF_MAX_LENGTH = 21;

    /**
     * The invoice currency amounts are read and written in. See Amounts::ZERO_DECIMAL.
     */
    private string $currency = '';

    /**
     * A mapper for one invoice currency. A zero-decimal currency is whole units in Fleetbase.
     */
    public function forCurrency(?string $currency): self
    {
        $mapper           = clone $this;
        $mapper->currency = strtoupper(trim((string) $currency));

        return $mapper;
    }

    /**
     * QuickBooks works out the tax, so no tax amount is sent: each line carries the tax code in
     * $tax['lines'] (by position), the invoice its rate, and a new invoice outside the US whether
     * its amounts include tax (see InvoiceTax::plan).
     *
     * $pushClears includes an empty note and due date so a Fleetbase clear is sent.
     *
     * @param array<string, mixed>                                                             $invoice
     * @param array{lines?: array<int, string|null>, rate?: string|null, global?: string|null} $tax
     *
     * @return array<string, mixed>
     */
    public function toQuickBooks(
        array $invoice,
        string $customerRef,
        string $itemId,
        bool $pushClears = false,
        array $tax = [],
    ): array {
        $lines    = [];
        $items    = is_array($invoice['items'] ?? null) === true ? $invoice['items'] : [];
        $currency = (string) ($invoice['currency'] ?? '');
        foreach (array_values($items) as $item) {
            $amountCents = (int) ($item['amount'] ?? 0);
            $qty         = (int) ($item['quantity'] ?? 1);
            $line        = [
                'Amount'              => Amounts::centsToDecimal($amountCents, $currency),
                'DetailType'          => 'SalesItemLineDetail',
                'Description'         => (string) ($item['description'] ?? ''),
                'SalesItemLineDetail' => [
                    'ItemRef'   => ['value' => $itemId],
                    'Qty'       => $qty,
                    'UnitPrice' => Amounts::unitPriceDecimal($amountCents, $qty, $currency),
                ],
            ];
            $lineId = trim((string) ($item['qbo_line_id'] ?? ''));
            if ($lineId !== '') {
                $line['Id'] = $lineId;
            }
            $lines[] = $line;
        }

        $payload = [
            'CustomerRef' => ['value' => $customerRef],
            'Line'        => $lines,
        ];

        return $this->withTax($this->withInvoiceFields($payload, $invoice, $pushClears), $tax);
    }

    /**
     * Put the QuickBooks product of each sales line on a payload, by position. A line without one
     * keeps the item it has.
     *
     * @param array<string, mixed> $payload
     * @param array<int, string>   $itemIds
     *
     * @return array<string, mixed>
     */
    public function withLineItems(array $payload, array $itemIds): array
    {
        $position = 0;
        foreach (is_array($payload['Line'] ?? null) === true ? $payload['Line'] : [] as $index => $line) {
            if (is_array($line) === false || (string) ($line['DetailType'] ?? '') !== 'SalesItemLineDetail') {
                continue;
            }
            $id = $itemIds[$position++] ?? '';
            if ($id !== '') {
                $payload['Line'][$index]['SalesItemLineDetail']['ItemRef'] = ['value' => $id];
            }
        }

        return $payload;
    }

    /**
     * Put the tax plan (InvoiceTax::plan) on a payload: each sales line its tax code, in order, and
     * the invoice its rate and tax-exclusive flag. Nothing is added for a missing or empty value.
     *
     * @param array<string, mixed>                                                             $payload
     * @param array{lines?: array<int, string|null>, rate?: string|null, global?: string|null} $tax
     *
     * @return array<string, mixed>
     */
    public function withTax(array $payload, array $tax): array
    {
        $codes    = $tax['lines'] ?? [];
        $position = 0;
        foreach (is_array($payload['Line'] ?? null) === true ? $payload['Line'] : [] as $index => $line) {
            if (is_array($line) === false || (string) ($line['DetailType'] ?? '') !== 'SalesItemLineDetail') {
                continue;
            }
            $code = $codes[$position++] ?? null;
            if (is_string($code) === true && $code !== '') {
                $payload['Line'][$index]['SalesItemLineDetail']['TaxCodeRef'] = ['value' => $code];
            }
        }
        if (is_string($tax['rate'] ?? null) === true && $tax['rate'] !== '') {
            $payload['TxnTaxDetail'] = ['TxnTaxCodeRef' => ['value' => $tax['rate']]];
        }
        if (is_string($tax['global'] ?? null) === true && $tax['global'] !== '') {
            $payload['GlobalTaxCalculation'] = $tax['global'];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $invoice
     *
     * @return array<string, mixed>
     */
    private function withInvoiceFields(array $payload, array $invoice, bool $pushClears): array
    {
        if (empty($invoice['number']) === false) {
            $payload['DocNumber'] = $invoice['number'];
        }
        if (empty($invoice['date']) === false) {
            $payload['TxnDate'] = $invoice['date'];
        } elseif ($pushClears === true) {
            $payload['TxnDate'] = '';
        }
        if (empty($invoice['due_date']) === false) {
            $payload['DueDate'] = $invoice['due_date'];
        } elseif ($pushClears === true) {
            $payload['DueDate'] = '';
        }
        if (empty($invoice['notes']) === false) {
            $payload['PrivateNote'] = self::privateNote((string) $invoice['notes']);
        } elseif ($pushClears === true) {
            $payload['PrivateNote'] = '';
        }
        if (empty($invoice['currency']) === false) {
            $payload['CurrencyRef'] = ['value' => $invoice['currency']];
        }

        return $payload;
    }

    /**
     * QuickBooks replaces the invoice Line array on update, including a sparse
     * update. Copy the Ids QuickBooks already stored onto the sales lines this
     * payload replaces. A line that already carries one of those ids keeps it;
     * other Fleetbase lines take the remaining ids in order. A matched line keeps
     * its QuickBooks product and tax code. Extra sales lines, and every other line
     * type, are included unchanged so the update does not delete them. The invoice
     * keeps the tax rate and tax-inclusive setting QuickBooks holds.
     * Sales lines whose item was deleted in Fleetbase
     * ($removed) are left out, so QuickBooks removes them. Once the invoice has stored
     * line ids ($mapped), a line without one pairs only with a line of the same content.
     *
     * $managed holds the QuickBooks products Fleetbase puts on lines, each with the name key it is
     * for (true for the service item), see withRemoteLineCodes().
     *
     * @param array<string, mixed>                                            $payload
     * @param array<string, mixed>                                            $remote
     * @param array<int, array{id: string, description: string, amount: int}> $removed
     * @param array<string, string|true>                                      $managed
     *
     * @return array<string, mixed>
     */
    public function withExistingLineIds(
        array $payload,
        array $remote,
        array $removed = [],
        bool $mapped = false,
        array $managed = [],
    ): array {
        $lines       = is_array($payload['Line'] ?? null) === true ? $payload['Line'] : [];
        $remote      = $this->withoutRemovedLines($remote, $removed);
        $remoteSales = $this->remoteSalesLines($remote);
        $wanted      = [];
        $salesAt     = [];
        foreach ($lines as $index => $line) {
            if (is_array($line) === false || (string) ($line['DetailType'] ?? '') !== 'SalesItemLineDetail') {
                continue;
            }
            $salesAt[] = $index;
            $wanted[]  = [
                'id'          => trim((string) ($line['Id'] ?? '')),
                'description' => (string) ($line['Description'] ?? ''),
                'amount'      => $this->lineAmount($line['Amount'] ?? 0),
            ];
        }
        $byPosition      = [];
        $paired          = $this->pairSalesLines($wanted, $remoteSales, $mapped, $byPosition);
        $payload['Line'] = $this->applyPairedLineIds(
            $lines,
            $remote,
            $remoteSales,
            $salesAt,
            $paired,
            $byPosition,
            $managed
        );
        // A new invoice says whether its amounts include tax; an update keeps what QuickBooks has.
        unset($payload['GlobalTaxCalculation']);

        return self::withRemoteTaxRate($payload, $remote);
    }

    /**
     * Whether an update would put a QuickBooks line on another product (withRemoteLineCodes), which
     * is a change to send even when the amounts and text are the same.
     *
     * @param array<string, mixed>                                            $payload
     * @param array<string, mixed>                                            $remote
     * @param array<int, array{id: string, description: string, amount: int}> $removed
     * @param array<string, string|true>                                      $managed
     */
    public function movesLineItems(array $payload, array $remote, array $removed, bool $mapped, array $managed): bool
    {
        $held = [];
        foreach ($this->remoteSalesLines($remote) as $line) {
            $id = trim((string) ($line['Id'] ?? ''));
            if ($id !== '') {
                $held[$id] = trim((string) ($line['SalesItemLineDetail']['ItemRef']['value'] ?? ''));
            }
        }
        $merged = $this->withExistingLineIds($payload, $remote, $removed, $mapped, $managed);
        foreach (is_array($merged['Line'] ?? null) === true ? $merged['Line'] : [] as $line) {
            $id    = is_array($line) === true ? trim((string) ($line['Id'] ?? '')) : '';
            $sales = is_array($line) === true && (string) ($line['DetailType'] ?? '') === 'SalesItemLineDetail';
            if ($id === '' || isset($held[$id]) === false || $sales === false) {
                continue;
            }
            $item = trim((string) ($line['SalesItemLineDetail']['ItemRef']['value'] ?? ''));
            if ($held[$id] !== '' && $item !== $held[$id]) {
                return true;
            }
        }

        return false;
    }

    /**
     * An invoice given a sales tax rate in QuickBooks keeps it, over the customer or company rate a
     * new invoice gets. QuickBooks refuses a line update that does not send the rate again (fault
     * 6000, "error while calculating tax").
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $remote
     *
     * @return array<string, mixed>
     */
    private static function withRemoteTaxRate(array $payload, array $remote): array
    {
        $ref  = $remote['TxnTaxDetail']['TxnTaxCodeRef'] ?? null;
        $rate = is_array($ref) === true ? trim((string) ($ref['value'] ?? '')) : '';
        if ($rate === '') {
            return $payload;
        }
        $detail                  = is_array($payload['TxnTaxDetail'] ?? null) === true ? $payload['TxnTaxDetail'] : [];
        $payload['TxnTaxDetail'] = ['TxnTaxCodeRef' => ['value' => $rate]] + $detail;

        return $payload;
    }

    /**
     * @param array<int, mixed>                $lines
     * @param array<string, mixed>             $remote
     * @param array<int, array<string, mixed>> $remoteSales
     * @param array<int, int>                  $salesAt
     * @param array<int, int|null>             $paired      remote sales index by Fleetbase line
     * @param array<int, true>                 $byPosition  Fleetbase lines paired only by their position
     * @param array<string, string|true>       $managed
     *
     * @return array<int, mixed>
     */
    private function applyPairedLineIds(
        array $lines,
        array $remote,
        array $remoteSales,
        array $salesAt,
        array $paired,
        array $byPosition,
        array $managed = [],
    ): array {
        $pairedRemote = [];
        foreach ($paired as $localIndex => $remoteIndex) {
            if ($remoteIndex === null) {
                continue;
            }
            $pairedRemote[$remoteIndex] = true;
            if (isset($salesAt[$localIndex]) === false) {
                continue;
            }
            $remoteLineId = trim((string) ($remoteSales[$remoteIndex]['Id'] ?? ''));
            if ($remoteLineId === '') {
                continue;
            }
            $at   = $salesAt[$localIndex];
            $line = $lines[$at];
            if (is_array($line) === true) {
                $line['Id'] = $remoteLineId;
                $keepCodes  = isset($byPosition[$localIndex]) === false;
                $lines[$at] = self::withRemoteLineCodes($line, $remoteSales[$remoteIndex], $keepCodes, $managed);
            }
        }
        foreach ($this->extraRemoteLines($remote, $pairedRemote) as $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * A line Fleetbase is updating keeps the product/service and tax code QuickBooks holds on it
     * when someone chose that product in QuickBooks. Not for a line paired only by position,
     * which may be a different line, and not for a product made inactive (renamed "... (deleted)").
     * A line on a product Fleetbase manages ($managed) takes the line's own product instead, so
     * a line moves from the service item to its product, or to the product of its new name. A
     * line that would fall back to the service item keeps a product of Fleetbase's its text still
     * names, active or not, so an old invoice keeps its product once the Storefront product is
     * gone (QuickBooks accepts an inactive product on a line that already has it).
     * A description Fleetbase holds as fleetbaseDescription() gives it is sent as the QuickBooks
     * text, so a cut description or a product name shown for an empty one is not written back.
     *
     * @param array<string, mixed>       $line
     * @param array<string, mixed>       $remote
     * @param array<string, string|true> $managed
     *
     * @return array<string, mixed>
     */
    private static function withRemoteLineCodes(array $line, array $remote, bool $keepCodes, array $managed = []): array
    {
        $detail   = is_array($remote['SalesItemLineDetail'] ?? null) === true ? $remote['SalesItemLineDetail'] : [];
        $held     = trim((string) ($detail['ItemRef']['value'] ?? ''));
        $wanted   = trim((string) ($line['SalesItemLineDetail']['ItemRef']['value'] ?? ''));
        $ours     = $held !== '' && array_key_exists($held, $managed) === true;
        $text     = (string) ($line['Description'] ?? '');
        $named    = $ours === true
            && is_string($managed[$held]) === true
            && $managed[$held] === ProductMapper::key(ProductMapper::feeName($text) ?? $text);
        $fallback = ($managed[$wanted] ?? false) === true;
        if ($ours === true && $held !== $wanted && ($fallback === false || $named === false)) {
            $keepCodes = false;
        }
        foreach ($keepCodes === true ? ['ItemRef', 'TaxCodeRef'] : [] as $key) {
            $ref     = is_array($detail[$key] ?? null) === true ? $detail[$key] : [];
            $deleted = $ours === false && str_ends_with((string) ($ref['name'] ?? ''), ' (deleted)');
            if (trim((string) ($ref['value'] ?? '')) !== '' && $deleted === false) {
                $line['SalesItemLineDetail'][$key] = $ref;
            }
        }
        if ((string) ($line['Description'] ?? '') === self::fleetbaseDescription($remote)) {
            $line['Description'] = (string) ($remote['Description'] ?? '');
        }

        return $line;
    }

    /**
     * A QuickBooks line's description as a Fleetbase line holds it: the product name when the line
     * has no description, cut to LINE_DESCRIPTION_MAX characters.
     *
     * @param array<string, mixed> $line
     */
    private static function fleetbaseDescription(array $line): string
    {
        $description = (string) ($line['Description'] ?? '');
        if (trim($description) === '' && is_array($line['SalesItemLineDetail']['ItemRef'] ?? null) === true) {
            $description = (string) ($line['SalesItemLineDetail']['ItemRef']['name'] ?? '');
        }

        return mb_substr($description, 0, self::LINE_DESCRIPTION_MAX);
    }

    /**
     * A Fleetbase description matches a QuickBooks line as fleetbaseDescription() gives it. An empty
     * Fleetbase description also matches a line with none, which shows its product name instead.
     *
     * @param array<string, mixed> $line
     */
    private static function sameDescription(string $local, array $line): bool
    {
        return $local === self::fleetbaseDescription($line)
            || ($local === '' && trim((string) ($line['Description'] ?? '')) === '');
    }

    /**
     * Unmatched sales lines and every line type Fleetbase does not write (discounts, subtotals,
     * description-only lines, bundles). A Line array replaces the invoice lines, so these have to
     * travel with the update unchanged.
     *
     * @param array<string, mixed> $remote
     * @param array<int, true>     $pairedSales remote sales indexes already copied onto Fleetbase lines
     *
     * @return array<int, array<string, mixed>>
     */
    private function extraRemoteLines(array $remote, array $pairedSales): array
    {
        $extra = [];
        $sales = 0;
        $lines = is_array($remote['Line'] ?? null) === true ? $remote['Line'] : [];
        foreach ($lines as $line) {
            if (is_array($line) === false) {
                continue;
            }
            $detail = (string) ($line['DetailType'] ?? '');
            if ($detail === 'SalesItemLineDetail') {
                if (isset($pairedSales[$sales]) === false) {
                    $extra[] = $line;
                }
                $sales++;
                continue;
            }
            if ($detail !== '') {
                // QuickBooks sends an empty detail as {}, which decodes to []. Sent back as [], the update is refused.
                $key = $detail === 'DescriptionOnly' ? 'DescriptionLineDetail' : $detail;
                if (($line[$key] ?? null) === []) {
                    $line[$key] = new \stdClass();
                }
                $extra[] = $line;
            }
        }

        return $extra;
    }

    /**
     * Remote sales lines that do not pair with a Fleetbase line are left out of the
     * comparison. A Fleetbase line that already has a QuickBooks id keeps that line.
     * The rest pair with the remaining remote sales lines in order. Lines past that
     * set stay in QuickBooks; their amount is returned so the compared total can
     * leave them out. Discount and subtotal lines are kept.
     * A line whose item was deleted in Fleetbase ($removed) is never paired and
     * stays in the comparison, so the invoice does not look aligned while it remains.
     *
     * @param array<int, mixed>                                               $localItems
     * @param array<string, mixed>                                            $remote
     * @param array<int, array{id: string, description: string, amount: int}> $removed
     *
     * @return array{remote: array<string, mixed>, omitted_cents: int}
     */
    public function withoutUnmatchedSalesLines(
        array $localItems,
        array $remote,
        array $removed = [],
        bool $mapped = false,
    ): array {
        $remoteSales = $this->remoteSalesLines($this->withoutRemovedLines($remote, $removed));
        $paired      = [];
        $wanted      = $this->wantedLines($localItems);
        foreach ($this->pairSalesLines($wanted, $remoteSales, $mapped) as $remoteIndex) {
            if ($remoteIndex !== null) {
                $paired[$remoteIndex] = true;
            }
        }

        $kept    = [];
        $omitted = 0;
        $cursor  = 0;
        $lines   = is_array($remote['Line'] ?? null) === true ? $remote['Line'] : [];
        foreach ($lines as $line) {
            $sales = is_array($line) === true && $this->isProductSalesLine($line) === true;
            if (is_array($line) === true && (string) ($line['DetailType'] ?? '') === 'GroupLineDetail') {
                // Fleetbase does not read bundles, and an update sends them back unchanged.
                $omitted += $this->lineAmount($line['Amount'] ?? 0);
            }
            if ($sales === false || $this->isRemovedLine($line, $removed) === true) {
                $kept[] = $line;
                continue;
            }
            if (isset($paired[$cursor]) === true) {
                $kept[] = $line;
            } else {
                $omitted += $this->lineAmount($line['Amount'] ?? 0);
            }
            $cursor++;
        }
        $filtered         = $remote;
        $filtered['Line'] = $kept;

        return ['remote' => $filtered, 'omitted_cents' => $omitted];
    }

    /**
     * The QuickBooks line id each Fleetbase item pairs with, by item position ('' when none).
     * Pairing is the same as for an update, so an aligned invoice records the lines it matched.
     *
     * @param array<int, mixed>                                               $localItems
     * @param array<string, mixed>                                            $remote
     * @param array<int, array{id: string, description: string, amount: int}> $removed
     *
     * @return array<int, string>
     */
    public function pairedLineIds(array $localItems, array $remote, array $removed = [], bool $mapped = false): array
    {
        $remoteSales = $this->remoteSalesLines($this->withoutRemovedLines($remote, $removed));
        $ids         = [];
        $wanted      = $this->wantedLines($localItems);
        foreach ($this->pairSalesLines($wanted, $remoteSales, $mapped) as $localIndex => $remoteIndex) {
            $ids[$localIndex] = $remoteIndex === null ? '' : trim((string) ($remoteSales[$remoteIndex]['Id'] ?? ''));
        }

        return $ids;
    }

    /**
     * Ids of the product sales lines, in order.
     *
     * @param array<string, mixed> $invoice
     *
     * @return array<int, string>
     */
    public function salesLineIds(array $invoice): array
    {
        $ids   = [];
        $lines = is_array($invoice['Line'] ?? null) === true ? $invoice['Line'] : [];
        foreach ($lines as $line) {
            if (is_array($line) === true && $this->isProductSalesLine($line) === true) {
                $ids[] = trim((string) ($line['Id'] ?? ''));
            }
        }

        return $ids;
    }

    /**
     * @param array<int, mixed> $localItems
     *
     * @return array<int, array{id: string, description: string, amount: int}>
     */
    private function wantedLines(array $localItems): array
    {
        $wanted = [];
        foreach ($localItems as $item) {
            if (is_array($item) === true) {
                $wanted[] = [
                    'id'          => trim((string) ($item['qbo_line_id'] ?? '')),
                    'description' => (string) ($item['description'] ?? ''),
                    'amount'      => (int) ($item['amount'] ?? 0),
                ];
            }
        }

        return $wanted;
    }

    /**
     * @param array<string, mixed>                                            $remote
     * @param array<int, array{id: string, description: string, amount: int}> $removed
     *
     * @return array<string, mixed>
     */
    private function withoutRemovedLines(array $remote, array $removed): array
    {
        if ($removed === [] || is_array($remote['Line'] ?? null) === false) {
            return $remote;
        }
        $remote['Line'] = array_values(array_filter(
            $remote['Line'],
            fn (mixed $line): bool => is_array($line) === false || $this->isRemovedLine($line, $removed) === false
        ));

        return $remote;
    }

    /**
     * A QuickBooks line is removed only while it still holds what the deleted Fleetbase item
     * held. A line edited in QuickBooks, or an id QuickBooks gave to another line, stays.
     *
     * @param array<string, mixed>                                            $line
     * @param array<int, array{id: string, description: string, amount: int}> $removed
     */
    private function isRemovedLine(array $line, array $removed): bool
    {
        $id = trim((string) ($line['Id'] ?? ''));
        if ($id === '' || $this->isProductSalesLine($line) === false) {
            return false;
        }
        foreach ($removed as $gone) {
            if ($gone['id'] === $id && $this->sameContent($gone, $line) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{description: string, amount: int} $local
     * @param array<string, mixed>                    $line
     */
    private function sameContent(array $local, array $line): bool
    {
        return self::sameDescription($local['description'], $line)
            && $local['amount'] === $this->lineAmount($line['Amount'] ?? 0);
    }

    /**
     * @param array<string, mixed> $remote
     *
     * @return array<int, array<string, mixed>>
     */
    private function remoteSalesLines(array $remote): array
    {
        $sales = [];
        $lines = is_array($remote['Line'] ?? null) === true ? $remote['Line'] : [];
        foreach ($lines as $line) {
            if (is_array($line) === true && $this->isProductSalesLine($line) === true) {
                $sales[] = $line;
            }
        }

        return $sales;
    }

    /**
     * A Fleetbase line pairs with the QuickBooks line of its stored id, then with an unused line
     * of the same description and amount. Without stored ids on the invoice, the rest pair in
     * order. With them, an unpaired line is new and gets no id, so a line added in QuickBooks
     * is not overwritten.
     *
     * @param array<int, array{id: string, description: string, amount: int}> $wantedLines
     * @param array<int, array<string, mixed>>                                $remoteSales
     * @param array<int, true>                                                $byPosition  lines paired in order
     *
     * @return array<int, int|null>
     */
    private function pairSalesLines(
        array $wantedLines,
        array $remoteSales,
        bool $mapped = false,
        array &$byPosition = [],
    ): array {
        $pairs   = [];
        $used    = [];
        $pending = [];
        foreach ($wantedLines as $localIndex => $line) {
            $found              = self::lineWithId(trim($line['id']), $remoteSales, $used);
            $pairs[$localIndex] = $found;
            if ($found === null) {
                $pending[] = $localIndex;
                continue;
            }
            $used[$found] = true;
        }
        $unpaired = [];
        foreach ($pending as $localIndex) {
            $found = $this->lineWithContent($wantedLines[$localIndex], $remoteSales, $used);
            if ($found === null) {
                $unpaired[] = $localIndex;
                continue;
            }
            $used[$found]       = true;
            $pairs[$localIndex] = $found;
        }
        if ($mapped === true) {
            return $pairs;
        }
        foreach ($unpaired as $localIndex) {
            foreach (array_keys($remoteSales) as $index) {
                if (isset($used[$index]) === true) {
                    continue;
                }
                $used[$index]            = true;
                $pairs[$localIndex]      = $index;
                $byPosition[$localIndex] = true;
                break;
            }
        }

        return $pairs;
    }

    /**
     * The first unused QuickBooks sales line with this id. An empty id matches none.
     *
     * @param array<int, array<string, mixed>> $remoteSales
     * @param array<int, bool>                 $used
     */
    private static function lineWithId(string $id, array $remoteSales, array $used): ?int
    {
        if ($id === '') {
            return null;
        }
        foreach ($remoteSales as $index => $line) {
            if (isset($used[$index]) === false && trim((string) ($line['Id'] ?? '')) === $id) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The first unused QuickBooks sales line with this line's description and amount.
     *
     * @param array{id: string, description: string, amount: int} $wanted
     * @param array<int, array<string, mixed>>                    $remoteSales
     * @param array<int, bool>                                    $used
     */
    private function lineWithContent(array $wanted, array $remoteSales, array $used): ?int
    {
        foreach ($remoteSales as $index => $line) {
            if (isset($used[$index]) === false && $this->sameContent($wanted, $line) === true) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function isProductSalesLine(array $line): bool
    {
        return (string) ($line['DetailType'] ?? '') === 'SalesItemLineDetail';
    }

    /**
     * What QuickBooks adds to the invoice lines on its own: tax it works out itself (TxnTaxDetail,
     * for example automated sales tax) less discount lines. Fleetbase sends neither and keeps the
     * discount lines on an update (withExistingLineIds), so this part of TotalAmt never matches a
     * Fleetbase total and is left out of the comparison.
     *
     * @param array<string, mixed> $remote
     */
    public function quickbooksAdjustmentCents(array $remote): int
    {
        $detail = is_array($remote['TxnTaxDetail'] ?? null) === true ? $remote['TxnTaxDetail'] : [];
        // Lines of a tax-inclusive invoice already hold the tax, so it is not added to them.
        $cents  = self::taxInclusive($remote) === true ? 0 : $this->lineAmount($detail['TotalTax'] ?? 0);
        $lines  = is_array($remote['Line'] ?? null) === true ? $remote['Line'] : [];
        foreach ($lines as $line) {
            if (is_array($line) === true && (string) ($line['DetailType'] ?? '') === 'DiscountLineDetail') {
                $cents -= abs($this->lineAmount($line['Amount'] ?? 0));
            }
        }

        return $cents;
    }

    /**
     * Copy the QuickBooks fields this mapper already understands.
     * Sales item lines become description (see fleetbaseDescription), quantity, unit price, amount
     * and the line's share of the tax (lineTaxes). The tax is the tax QuickBooks calculated.
     * Discounts, products, and other line types are omitted; TotalAmt is still returned. A line
     * with no description stays empty when the Fleetbase line in its place ($localItems) is empty
     * too, so it is not a change.
     *
     * @param array<string, mixed> $remote
     * @param array<int, mixed>    $localItems
     *
     * @return array{items: array<int, array<string, mixed>>, tax: int, total: int}
     */
    public function fromQuickBooks(array $remote, array $localItems = []): array
    {
        $items   = [];
        $taxable = [];
        $lines   = is_array($remote['Line'] ?? null) === true ? $remote['Line'] : [];
        foreach ($lines as $line) {
            if (is_array($line) === false || $this->isProductSalesLine($line) === false) {
                continue;
            }

            $description      = self::fleetbaseDescription($line);
            $amountCents      = $this->lineAmount($line['Amount'] ?? 0);
            $local            = $localItems[count($items)] ?? null;
            $localDescription = is_array($local) === true ? (string) ($local['description'] ?? '') : null;
            if ($localDescription !== null && self::sameDescription($localDescription, $line) === true) {
                $description = $localDescription;
            }

            $detail    = is_array($line['SalesItemLineDetail'] ?? null) === true ? $line['SalesItemLineDetail'] : [];
            $taxable[] = trim((string) ($detail['TaxCodeRef']['value'] ?? '')) === 'TAX';
            $quantity  = self::wholeQuantity($detail['Qty'] ?? 1);
            if ($quantity === null) {
                // A Fleetbase line quantity is a whole number, so 1.5 hours cannot be kept as 1.5.
                // One unit at the line amount keeps quantity times price equal to the QuickBooks line.
                $items[] = self::item($description, 1, $amountCents, $amountCents);
                continue;
            }
            $unitCents = array_key_exists('UnitPrice', $detail) === true
                ? $this->lineAmount($detail['UnitPrice'])
                : ($quantity > 0 ? intdiv($amountCents, max($quantity, 1)) : $amountCents);
            $items[] = self::item($description, $quantity > 0 ? $quantity : 1, $unitCents, $amountCents);
        }
        $taxDetail = is_array($remote['TxnTaxDetail'] ?? null) === true ? $remote['TxnTaxDetail'] : [];
        $tax       = $this->lineAmount($taxDetail['TotalTax'] ?? 0);

        return [
            'items' => $this->lineTaxes($items, $taxable, $tax, $taxDetail),
            'tax'   => $tax,
            'total' => $this->lineAmount($remote['TotalAmt'] ?? 0),
        ];
    }

    /**
     * The tax QuickBooks worked out on the lines Fleetbase sent. The tax is shared by amount over
     * the QuickBooks lines coded TAX (US), the rounding left on the last one, and each Fleetbase
     * item takes the share of its QuickBooks line (qbo_line_id), so the tax of a line only
     * QuickBooks has is left out and the item taxes add up to the invoice tax. When the tax cannot
     * be shared (no TAX line with a positive taxed amount, or a rate a Ledger line cannot hold, as
     * outside the US) the whole tax is kept on the invoice and the items get none.
     *
     * @param array<string, mixed> $remote
     * @param array<int, mixed>    $items
     *
     * @return array{tax: int, items: array<int, array<string, mixed>>, inclusive: bool}
     */
    public function quickBooksTax(array $remote, array $items): array
    {
        $detail = is_array($remote['TxnTaxDetail'] ?? null) === true ? $remote['TxnTaxDetail'] : [];
        $tax    = $this->lineAmount($detail['TotalTax'] ?? 0);
        $taxed  = [];
        $base   = 0;
        foreach (is_array($remote['Line'] ?? null) === true ? $remote['Line'] : [] as $line) {
            $id   = is_array($line) === true ? trim((string) ($line['Id'] ?? '')) : '';
            $code = $id !== '' ? trim((string) ($line['SalesItemLineDetail']['TaxCodeRef']['value'] ?? '')) : '';
            if ($code === 'TAX' && $this->isProductSalesLine($line) === true) {
                $taxed[$id] = $this->lineAmount($line['Amount'] ?? 0);
                $base += $taxed[$id];
            }
        }
        $rate   = $base > 0 ? (self::taxPercent($detail) ?? ($tax / $base * 100)) : 0.0;
        $shares = [];
        if ($tax !== 0 && $base > 0 && $rate > 0 && $rate <= self::LINE_TAX_RATE_MAX) {
            $left = $tax;
            $last = array_key_last($taxed);
            foreach ($taxed as $id => $amount) {
                $shares[$id] = $id === $last ? $left : self::share($tax, $amount, $base);
                $left -= $shares[$id];
            }
        }
        $clean = [];
        $sum   = 0;
        foreach ($items as $index => $item) {
            if (is_array($item) === false) {
                continue;
            }
            $share              = $shares[trim((string) ($item['qbo_line_id'] ?? ''))] ?? null;
            $item['tax_rate']   = $share === null ? '0.00' : self::taxRate($rate);
            $item['tax_amount'] = $share ?? 0;
            $sum += $item['tax_amount'];
            $clean[$index]      = $item;
        }

        return ['tax' => $shares === [] ? $tax : $sum, 'items' => $clean, 'inclusive' => self::taxInclusive($remote)];
    }

    /**
     * Whether the QuickBooks invoice amounts include tax (non-US companies only).
     *
     * @param array<string, mixed> $remote
     */
    public static function taxInclusive(array $remote): bool
    {
        return (string) ($remote['GlobalTaxCalculation'] ?? '') === 'TaxInclusive';
    }

    /**
     * $tax times $amount over $base, rounded half away from zero, in integers.
     */
    private static function share(int $tax, int $amount, int $base): int
    {
        $product = $tax * $amount;
        $sign    = $product < 0 ? -1 : 1;

        return $sign * intdiv(2 * abs($product) + $base, 2 * $base);
    }

    /**
     * A Fleetbase invoice line, with its tax fields in the order the directory reads them.
     *
     * @return array{
     *     description: string, quantity: int, unit_price: int, amount: int, tax_rate: string, tax_amount: int
     * }
     */
    public static function item(
        string $description,
        int $quantity,
        int $unitPrice,
        int $amount,
        string $rate = '0.00',
        int $tax = 0,
    ): array {
        return [
            'description' => $description,
            'quantity'    => $quantity,
            'unit_price'  => $unitPrice,
            'amount'      => $amount,
            'tax_rate'    => $rate,
            'tax_amount'  => $tax,
        ];
    }

    /**
     * A Ledger line tax rate as the directory reads it: two decimals.
     */
    public static function taxRate(mixed $rate): string
    {
        return number_format(is_numeric($rate) === true ? (float) $rate : 0.0, 2, '.', '');
    }

    /**
     * Share the invoice tax over the US lines coded TAX, so the Ledger line taxes add up to it and a
     * Ledger edit, which recalculates each line's tax from its rate, keeps it. The rate is the sum of
     * the QuickBooks tax line percents, else the tax over the taxed amount. Amounts are shared by
     * line amount, the rounding left on the last taxed line. Nothing is shared when the taxed amount
     * is not positive or the rate does not fit a Ledger line. Lines outside the US carry tax codes
     * whose rates are not read here, so they keep none.
     *
     * @param array<int, array<string, mixed>> $items
     * @param array<int, bool>                 $taxable
     * @param array<string, mixed>             $taxDetail
     *
     * @return array<int, array<string, mixed>>
     */
    private function lineTaxes(array $items, array $taxable, int $tax, array $taxDetail): array
    {
        $taxed = array_keys(array_filter($taxable));
        $base  = 0;
        foreach ($taxed as $index) {
            $base += (int) $items[$index]['amount'];
        }
        if ($tax === 0 || $base <= 0) {
            return $items;
        }
        $rate = self::taxPercent($taxDetail) ?? ($tax / $base * 100);
        if ($rate <= 0 || $rate > self::LINE_TAX_RATE_MAX) {
            return $items;
        }
        $left = $tax;
        $last = end($taxed);
        foreach ($taxed as $index) {
            $amount                        = (int) $items[$index]['amount'];
            $share                         = $index === $last ? $left : self::share($tax, $amount, $base);
            $left -= $share;
            $items[$index]['tax_rate']     = self::taxRate($rate);
            $items[$index]['tax_amount']   = $share;
        }

        return $items;
    }

    /**
     * The summed percent of QuickBooks' tax lines, or null when one is not percent based.
     *
     * @param array<string, mixed> $taxDetail
     */
    private static function taxPercent(array $taxDetail): ?float
    {
        $lines = is_array($taxDetail['TaxLine'] ?? null) === true ? $taxDetail['TaxLine'] : [];
        $sum   = 0.0;
        foreach ($lines as $line) {
            $detail = is_array($line) === true ? ($line['TaxLineDetail'] ?? null) : null;
            $detail = is_array($detail) === true ? $detail : [];
            if (($detail['PercentBased'] ?? false) !== true || is_numeric($detail['TaxPercent'] ?? null) === false) {
                return null;
            }
            $sum += (float) $detail['TaxPercent'];
        }

        return $lines === [] ? null : $sum;
    }

    /**
     * The whole-number quantity of a QuickBooks line, or null when it has a fractional part.
     * A non-numeric or missing quantity is 1.
     */
    private static function wholeQuantity(mixed $quantity): ?int
    {
        if (is_int($quantity) === true) {
            return $quantity;
        }
        if (is_float($quantity) === true) {
            $whole = $quantity === floor($quantity);

            return $whole === true ? (int) $quantity : null;
        }
        if (is_string($quantity) === true && preg_match('/^\s*(-?\d+)(?:\.(\d+))?\s*$/', $quantity, $parts) === 1) {
            return trim($parts[2] ?? '', '0') === '' ? (int) $parts[1] : null;
        }

        return 1;
    }

    private function lineAmount(mixed $amount): int
    {
        if (is_float($amount) === true) {
            throw new \InvalidArgumentException('Money must not be a float.');
        }
        if (is_int($amount) === false && is_string($amount) === false) {
            return 0;
        }

        return Amounts::toMinorUnits($amount, $this->currency);
    }

    /**
     * @return array<string, mixed>
     */
    public function payment(
        string $customerRef,
        string $invoiceId,
        int $amountCents,
        ?string $txnDate = null,
        ?string $reference = null,
        ?string $method = null,
    ): array {
        $amount  = Amounts::centsToDecimal($amountCents, $this->currency);
        $payload = [
            'TotalAmt'    => $amount,
            'CustomerRef' => ['value' => $customerRef],
            'Line'        => [[
                'Amount'    => $amount,
                'LinkedTxn' => [[
                    'TxnId'   => $invoiceId,
                    'TxnType' => 'Invoice',
                ]],
            ]],
        ];
        $txnDate = trim((string) $txnDate);
        if ($txnDate !== '') {
            $payload['TxnDate'] = substr($txnDate, 0, 10);
        }
        // QuickBooks keeps at most 21 characters of a payment reference number.
        $reference = trim((string) $reference);
        if ($reference !== '') {
            $payload['PaymentRefNum'] = mb_substr($reference, 0, self::PAYMENT_REF_MAX_LENGTH);
        }
        // "manual" is Ledger's default when nobody chose a method, so it says nothing.
        $method = trim((string) $method);
        if ($method !== '' && strtolower($method) !== 'manual') {
            $payload['PrivateNote'] = Translation::get(
                'quickbooks.messages.payment-method-note',
                ['method' => mb_substr($method, 0, 100)]
            );
        }

        return $payload;
    }
}
