<?php

namespace Fleetbase\Quickbooks\Tests\Support;

/**
 * Invoice lines for the line-pairing tests.
 */
class InvoiceLines
{
    /**
     * A QuickBooks sales line of one unit.
     *
     * @return array<string, mixed>
     */
    public static function sales(string $id, string $description, string $amount): array
    {
        return [
            'Id'                  => $id,
            'Amount'              => $amount,
            'DetailType'          => 'SalesItemLineDetail',
            'Description'         => $description,
            'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => $amount, 'ItemRef' => ['value' => 'item-1']],
        ];
    }

    /**
     * A QuickBooks tax line of the invoice tax detail, at a percent rate.
     *
     * @return array<string, mixed>
     */
    public static function tax(string $amount, string $percent): array
    {
        return [
            'Amount'        => $amount,
            'DetailType'    => 'TaxLineDetail',
            'TaxLineDetail' => ['PercentBased' => true, 'TaxPercent' => $percent],
        ];
    }

    /**
     * A tax line of a company outside the US: the amount of one QuickBooks tax rate.
     *
     * @return array<string, mixed>
     */
    public static function rateTax(string $amount, string $percent, string $rateId, string $net): array
    {
        $line                                      = self::tax($amount, $percent);
        $line['TaxLineDetail']['TaxRateRef']       = ['value' => $rateId];
        $line['TaxLineDetail']['NetAmountTaxable'] = $net;

        return $line;
    }

    /**
     * A sales line with a QuickBooks tax code.
     *
     * @return array<string, mixed>
     */
    public static function coded(string $id, string $description, string $amount, string $code): array
    {
        $line                                      = self::sales($id, $description, $amount);
        $line['SalesItemLineDetail']['TaxCodeRef'] = ['value' => $code];

        return $line;
    }

    /**
     * A deleted Fleetbase line whose QuickBooks line was stored on an earlier sync.
     *
     * @return array<string, mixed>
     */
    public static function removed(
        string $uuid,
        string $description,
        int $cents,
        string $lineId,
        string $realm = 'realm-1',
        string $invoice = 'qb-1',
    ): array {
        return [
            'uuid'        => $uuid,
            'realm'       => $realm,
            'invoice'     => $invoice,
            'id'          => $lineId,
            'description' => $description,
            'amount'      => $cents,
        ];
    }

    /**
     * A Fleetbase line of one unit, with no stored QuickBooks line.
     *
     * @return array<string, mixed>
     */
    public static function item(string $uuid, string $description, int $cents): array
    {
        return [
            'description' => $description,
            'quantity'    => 1,
            'unit_price'  => $cents,
            'amount'      => $cents,
            'uuid'        => $uuid,
        ];
    }

    /**
     * A Fleetbase line whose QuickBooks line id (on invoice qb-1 in realm-1) was stored on an earlier sync.
     *
     * @return array<string, mixed>
     */
    public static function tracked(string $uuid, string $description, int $cents, string $lineId): array
    {
        return [
            'description' => $description,
            'quantity'    => 1,
            'unit_price'  => $cents,
            'amount'      => $cents,
            'uuid'        => $uuid,
            'qbo_line'    => ['realm' => 'realm-1', 'invoice' => 'qb-1', 'id' => $lineId],
        ];
    }
}
