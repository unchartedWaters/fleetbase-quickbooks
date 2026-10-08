<?php

namespace Fleetbase\Quickbooks\Support;

class InvoiceMapper
{
    /**
     * Word joiner prefix so a product the user named "Tax" is not treated as this line.
     */
    public const TAX_LINE_DESCRIPTION = "\u{2060}Tax";

    /**
     * @param array<string, mixed> $invoice
     * @param bool                 $pushClears include an empty note and due date so a Fleetbase clear is sent
     *
     * @return array<string, mixed>
     */
    public function toQuickBooks(array $invoice, string $customerRef, string $itemId, bool $pushClears = false): array
    {
        $lines = [];
        $items = is_array($invoice['items'] ?? null) === true ? $invoice['items'] : [];
        foreach ($items as $item) {
            $amountCents = (int) ($item['amount'] ?? 0);
            $qty         = (int) ($item['quantity'] ?? 1);
            $line        = [
                'Amount'              => Amounts::centsToDecimal($amountCents),
                'DetailType'          => 'SalesItemLineDetail',
                'Description'         => (string) ($item['description'] ?? ''),
                'SalesItemLineDetail' => [
                    'ItemRef'   => ['value' => $itemId],
                    'Qty'       => $qty,
                    'UnitPrice' => Amounts::unitPriceDecimal($amountCents, $qty),
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
                'Amount'              => Amounts::centsToDecimal($tax),
                'DetailType'          => 'SalesItemLineDetail',
                'Description'         => self::TAX_LINE_DESCRIPTION,
                'SalesItemLineDetail' => [
                    'ItemRef'   => ['value' => $itemId],
                    'Qty'       => 1,
                    'UnitPrice' => Amounts::centsToDecimal($tax),
                ],
            ];
        }

        $payload = [
            'CustomerRef' => ['value' => $customerRef],
            'Line'        => $lines,
        ];

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
     * to zero instead of leaving it.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $remote
     *
     * @return array<string, mixed>
     */
    public function withExistingLineIds(array $payload, array $remote): array
    {
        $lines       = is_array($payload['Line'] ?? null) === true ? $payload['Line'] : [];
        $taxId       = null;
        $itemId      = '';
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
            $wanted[]  = trim((string) ($line['Id'] ?? ''));
            $ref       = is_array($line['SalesItemLineDetail']['ItemRef'] ?? null) === true
                ? trim((string) ($line['SalesItemLineDetail']['ItemRef']['value'] ?? ''))
                : '';
            if ($ref !== '') {
                $itemId = $ref;
            }
        }
        $pairedRemote = [];
        foreach ($this->pairSalesLines($wanted, $remoteSales) as $localIndex => $remoteIndex) {
            if ($remoteIndex === null) {
                continue;
            }
            $pairedRemote[$remoteIndex] = true;
            if (isset($salesAt[$localIndex]) === false) {
                continue;
            }
            $id = trim((string) ($remoteSales[$remoteIndex]['Id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $lines[$salesAt[$localIndex]]['Id'] = $id;
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
        $payload['Line'] = $lines;

        return $payload;
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
     *
     * @param array<int, mixed>    $localItems
     * @param array<string, mixed> $remote
     *
     * @return array{remote: array<string, mixed>, omitted_cents: int}
     */
    public function withoutUnmatchedSalesLines(array $localItems, array $remote): array
    {
        $taxId       = null;
        $itemId      = '';
        $remoteSales = $this->remoteSalesLines($remote, $taxId, $itemId);
        $wanted      = [];
        foreach ($localItems as $item) {
            if (is_array($item) === false) {
                continue;
            }
            $wanted[] = trim((string) ($item['qbo_line_id'] ?? ''));
        }
        $paired = [];
        foreach ($this->pairSalesLines($wanted, $remoteSales) as $remoteIndex) {
            if ($remoteIndex !== null) {
                $paired[$remoteIndex] = true;
            }
        }

        $kept    = [];
        $omitted = 0;
        $cursor  = 0;
        $lines   = is_array($remote['Line'] ?? null) === true ? $remote['Line'] : [];
        foreach ($lines as $line) {
            if (is_array($line) === false || $this->isProductSalesLine($line) === false) {
                $kept[] = $line;
                continue;
            }
            if (isset($paired[$cursor]) === true) {
                $kept[] = $line;
            } else {
                $omitted += self::lineAmount($line['Amount'] ?? 0);
            }
            $cursor++;
        }
        $filtered         = $remote;
        $filtered['Line'] = $kept;

        return ['remote' => $filtered, 'omitted_cents' => $omitted];
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
     * @param array<int, string>               $wantedIds
     * @param array<int, array<string, mixed>> $remoteSales
     *
     * @return array<int, int|null>
     */
    private function pairSalesLines(array $wantedIds, array $remoteSales): array
    {
        $pairs   = [];
        $used    = [];
        $pending = [];
        foreach ($wantedIds as $localIndex => $wanted) {
            $pairs[$localIndex] = null;
            $wanted             = trim($wanted);
            if ($wanted === '') {
                $pending[] = $localIndex;
                continue;
            }
            $found = null;
            foreach ($remoteSales as $index => $line) {
                $remoteId = trim((string) ($line['Id'] ?? ''));
                if (isset($used[$index]) === true || $remoteId === '' || $remoteId !== $wanted) {
                    continue;
                }
                $found = $index;
                break;
            }
            if ($found === null) {
                $pending[] = $localIndex;
                continue;
            }
            $used[$found]       = true;
            $pairs[$localIndex] = $found;
        }
        foreach ($pending as $localIndex) {
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
            $amountCents = self::lineAmount($line['Amount'] ?? 0);
            if ($description === self::TAX_LINE_DESCRIPTION) {
                $tax += $amountCents;
                continue;
            }

            $detail    = is_array($line['SalesItemLineDetail'] ?? null) === true ? $line['SalesItemLineDetail'] : [];
            $quantity  = (int) ($detail['Qty'] ?? 1);
            $unitCents = array_key_exists('UnitPrice', $detail) === true
                ? self::lineAmount($detail['UnitPrice'])
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
            'total' => self::lineAmount($remote['TotalAmt'] ?? 0),
        ];
    }

    private static function lineAmount(mixed $amount): int
    {
        if (is_float($amount) === true) {
            throw new \InvalidArgumentException('Money must not be a float.');
        }
        if (is_int($amount) === false && is_string($amount) === false) {
            return 0;
        }

        return Amounts::toMinorUnits($amount);
    }

    /**
     * @return array<string, mixed>
     */
    public function payment(string $customerRef, string $invoiceId, int $amountCents, ?string $txnDate = null): array
    {
        $amount  = Amounts::centsToDecimal($amountCents);
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
