<?php

namespace Fleetbase\Quickbooks\Support;

class InvoiceMapper
{
    /**
     * Word joiner prefix so a product the user named "Tax" is not treated as this line.
     */
    public const TAX_LINE_DESCRIPTION = "\u{2060}Tax";

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
     * @param array<string, mixed> $invoice
     * @param bool                 $pushClears include an empty note and due date so a Fleetbase clear is sent
     *
     * @return array<string, mixed>
     */
    public function toQuickBooks(array $invoice, string $customerRef, string $itemId, bool $pushClears = false): array
    {
        $lines    = [];
        $items    = is_array($invoice['items'] ?? null) === true ? $invoice['items'] : [];
        $currency = (string) ($invoice['currency'] ?? '');
        foreach ($items as $item) {
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

        $tax = (int) ($invoice['tax'] ?? 0);
        if ($tax > 0) {
            $lines[] = [
                'Amount'              => Amounts::centsToDecimal($tax, $currency),
                'DetailType'          => 'SalesItemLineDetail',
                'Description'         => self::TAX_LINE_DESCRIPTION,
                'SalesItemLineDetail' => [
                    'ItemRef'   => ['value' => $itemId],
                    'Qty'       => 1,
                    'UnitPrice' => Amounts::centsToDecimal($tax, $currency),
                ],
            ];
        }

        $payload = [
            'CustomerRef' => ['value' => $customerRef],
            'Line'        => $lines,
        ];

        return $this->withInvoiceFields($payload, $invoice, $pushClears);
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
            $payload['PrivateNote'] = $invoice['notes'];
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
     * other Fleetbase lines take the remaining ids in order. Extra sales lines,
     * and discount and subtotal lines, are included unchanged so the update
     * does not delete them. A cleared tax amount updates the existing tax line
     * to zero instead of leaving it. Sales lines whose item was deleted in Fleetbase
     * ($removed) are left out, so QuickBooks removes them. Once the invoice has stored
     * line ids ($mapped), a line without one pairs only with a line of the same content.
     *
     * @param array<string, mixed>                                            $payload
     * @param array<string, mixed>                                            $remote
     * @param array<int, array{id: string, description: string, amount: int}> $removed
     *
     * @return array<string, mixed>
     */
    public function withExistingLineIds(array $payload, array $remote, array $removed = [], bool $mapped = false): array
    {
        $lines       = is_array($payload['Line'] ?? null) === true ? $payload['Line'] : [];
        $taxId       = null;
        $itemId      = '';
        $remote      = $this->withoutRemovedLines($remote, $removed);
        $remoteSales = $this->remoteSalesLines($remote, $taxId, $itemId);
        $wanted      = [];
        $salesAt     = [];
        $hasTax      = false;
        foreach ($lines as $index => $line) {
            if (is_array($line) === false) {
                continue;
            }
            if ((string) ($line['Description'] ?? '') === self::TAX_LINE_DESCRIPTION) {
                $hasTax = true;
                if ($taxId !== null) {
                    $lines[$index]['Id'] = $taxId;
                }
                continue;
            }
            if ((string) ($line['DetailType'] ?? '') !== 'SalesItemLineDetail') {
                continue;
            }
            $salesAt[] = $index;
            $wanted[]  = [
                'id'          => trim((string) ($line['Id'] ?? '')),
                'description' => (string) ($line['Description'] ?? ''),
                'amount'      => $this->lineAmount($line['Amount'] ?? 0),
            ];
            $itemId    = self::itemRef($line) ?? $itemId;
        }
        $paired          = $this->pairSalesLines($wanted, $remoteSales, $mapped);
        $payload['Line'] = $this->applyPairedLineIds(
            $lines,
            $remote,
            $remoteSales,
            $salesAt,
            $paired,
            $hasTax,
            $taxId,
            $itemId
        );

        return $payload;
    }

    /**
     * @param array<int, mixed>                $lines
     * @param array<string, mixed>             $remote
     * @param array<int, array<string, mixed>> $remoteSales
     * @param array<int, int>                  $salesAt
     * @param array<int, int|null>             $paired      remote sales index by Fleetbase line
     *
     * @return array<int, mixed>
     */
    private function applyPairedLineIds(
        array $lines,
        array $remote,
        array $remoteSales,
        array $salesAt,
        array $paired,
        bool $hasTax,
        ?string $taxId,
        string $itemId,
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
            $lines[$salesAt[$localIndex]]['Id'] = $remoteLineId;
        }
        if ($hasTax === false && $taxId !== null) {
            $zero    = Amounts::centsToDecimal(0);
            $lines[] = [
                'Id'                  => $taxId,
                'Amount'              => $zero,
                'DetailType'          => 'SalesItemLineDetail',
                'Description'         => self::TAX_LINE_DESCRIPTION,
                'SalesItemLineDetail' => [
                    'ItemRef'   => ['value' => $itemId !== '' ? $itemId : '1'],
                    'Qty'       => 1,
                    'UnitPrice' => $zero,
                ],
            ];
        }
        foreach ($this->extraRemoteLines($remote, $pairedRemote) as $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Unmatched sales lines, discounts, and subtotals. A Line array replaces
     * the invoice lines, so these have to travel with the update unchanged.
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
                if ((string) ($line['Description'] ?? '') === self::TAX_LINE_DESCRIPTION) {
                    continue;
                }
                if (isset($pairedSales[$sales]) === false) {
                    $extra[] = $line;
                }
                $sales++;
                continue;
            }
            if ($detail === 'DiscountLineDetail' || $detail === 'SubTotalLineDetail') {
                // QuickBooks sends an empty detail as {}, which decodes to []. Sent back as [], the update is refused.
                if (($line[$detail] ?? null) === []) {
                    $line[$detail] = new \stdClass();
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
     * leave them out. Discount, subtotal, and the synthetic tax line are kept.
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
        $taxId       = null;
        $itemId      = '';
        $remoteSales = $this->remoteSalesLines($this->withoutRemovedLines($remote, $removed), $taxId, $itemId);
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
        $taxId       = null;
        $itemId      = '';
        $remoteSales = $this->remoteSalesLines($this->withoutRemovedLines($remote, $removed), $taxId, $itemId);
        $ids         = [];
        $wanted      = $this->wantedLines($localItems);
        foreach ($this->pairSalesLines($wanted, $remoteSales, $mapped) as $localIndex => $remoteIndex) {
            $ids[$localIndex] = $remoteIndex === null ? '' : trim((string) ($remoteSales[$remoteIndex]['Id'] ?? ''));
        }

        return $ids;
    }

    /**
     * Ids of the product sales lines, in order. The synthetic tax line is skipped.
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
        return $local['description'] === (string) ($line['Description'] ?? '')
            && $local['amount'] === $this->lineAmount($line['Amount'] ?? 0);
    }

    /**
     * @param array<string, mixed> $remote
     *
     * @return array<int, array<string, mixed>>
     */
    private function remoteSalesLines(array $remote, ?string &$taxId, string &$itemId): array
    {
        $sales = [];
        $lines = is_array($remote['Line'] ?? null) === true ? $remote['Line'] : [];
        foreach ($lines as $line) {
            if (is_array($line) === false || (string) ($line['DetailType'] ?? '') !== 'SalesItemLineDetail') {
                continue;
            }
            $detail = is_array($line['SalesItemLineDetail'] ?? null) === true ? $line['SalesItemLineDetail'] : [];
            $ref    = is_array($detail['ItemRef'] ?? null) === true ? trim((string) ($detail['ItemRef']['value'] ?? '')) : '';
            if ($ref !== '' && $itemId === '') {
                $itemId = $ref;
            }
            if ((string) ($line['Description'] ?? '') === self::TAX_LINE_DESCRIPTION) {
                $id = trim((string) ($line['Id'] ?? ''));
                if ($id !== '') {
                    $taxId = $id;
                }
                continue;
            }
            $sales[] = $line;
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
     *
     * @return array<int, int|null>
     */
    private function pairSalesLines(array $wantedLines, array $remoteSales, bool $mapped = false): array
    {
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
                $used[$index]       = true;
                $pairs[$localIndex] = $index;
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
     * The item id of a payload sales line, or null when it names none.
     *
     * @param array<mixed> $line
     */
    private static function itemRef(array $line): ?string
    {
        $ref = is_array($line['SalesItemLineDetail']['ItemRef'] ?? null) === true
            ? trim((string) ($line['SalesItemLineDetail']['ItemRef']['value'] ?? ''))
            : '';

        return $ref === '' ? null : $ref;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function isProductSalesLine(array $line): bool
    {
        return (string) ($line['DetailType'] ?? '') === 'SalesItemLineDetail'
            && (string) ($line['Description'] ?? '') !== self::TAX_LINE_DESCRIPTION;
    }

    /**
     * Copy the QuickBooks fields this mapper already understands.
     * Sales item lines become description, quantity, unit price, and amount.
     * Only the synthetic tax line this mapper writes is stored as tax. Discounts, products, and other
     * line types are omitted; TotalAmt is still returned.
     *
     * @param array<string, mixed> $remote
     *
     * @return array{items: array<int, array<string, mixed>>, tax: int, total: int}
     */
    public function fromQuickBooks(array $remote): array
    {
        $items = [];
        $tax   = 0;
        $lines = is_array($remote['Line'] ?? null) === true ? $remote['Line'] : [];
        foreach ($lines as $line) {
            if (is_array($line) === false || (string) ($line['DetailType'] ?? '') !== 'SalesItemLineDetail') {
                continue;
            }

            $description = (string) ($line['Description'] ?? '');
            $amountCents = $this->lineAmount($line['Amount'] ?? 0);
            if ($description === self::TAX_LINE_DESCRIPTION) {
                $tax += $amountCents;
                continue;
            }

            $detail   = is_array($line['SalesItemLineDetail'] ?? null) === true ? $line['SalesItemLineDetail'] : [];
            $quantity = self::wholeQuantity($detail['Qty'] ?? 1);
            if ($quantity === null) {
                // A Fleetbase line quantity is a whole number, so 1.5 hours cannot be kept as 1.5.
                // One unit at the line amount keeps quantity times price equal to the QuickBooks line.
                $items[] = [
                    'description' => $description,
                    'quantity'    => 1,
                    'unit_price'  => $amountCents,
                    'amount'      => $amountCents,
                ];
                continue;
            }
            $unitCents = array_key_exists('UnitPrice', $detail) === true
                ? $this->lineAmount($detail['UnitPrice'])
                : ($quantity > 0 ? intdiv($amountCents, max($quantity, 1)) : $amountCents);
            $items[] = [
                'description' => $description,
                'quantity'    => $quantity > 0 ? $quantity : 1,
                'unit_price'  => $unitCents,
                'amount'      => $amountCents,
            ];
        }

        return [
            'items' => $items,
            'tax'   => $tax,
            'total' => $this->lineAmount($remote['TotalAmt'] ?? 0),
        ];
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
    public function payment(string $customerRef, string $invoiceId, int $amountCents, ?string $txnDate = null): array
    {
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

        return $payload;
    }
}
