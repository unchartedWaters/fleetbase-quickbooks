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
            $lines[]     = [
                'Amount'              => Amounts::centsToDecimal($amountCents),
                'DetailType'          => 'SalesItemLineDetail',
                'Description'         => (string) ($item['description'] ?? ''),
                'SalesItemLineDetail' => [
                    'ItemRef'   => ['value' => $itemId],
                    'Qty'       => $qty,
                    'UnitPrice' => Amounts::unitPriceDecimal($amountCents, $qty),
                ],
            ];
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
