<?php

namespace Fleetbase\Quickbooks\Support;

/**
 * QuickBooks works out invoice tax from what it is sent. Its API applies none of the company's
 * tax settings by itself (checked in a US sandbox): a line with no tax code is not taxed, the
 * product's and customer's Taxable flags are ignored, and tax is calculated only when the invoice
 * names a rate. So Fleetbase sends the tax code of each line and the invoice rate, read from the
 * QuickBooks product, customer and company.
 *
 * @phpstan-type Settings array{
 *     mode: string, automated: bool, rate: string|null, codes?: array<string, array<int, string>>
 * }
 * @phpstan-type Plan array{lines: array<int, string|null>, rate: string|null, global: string|null}
 */
class InvoiceTax
{
    /** Sales tax is off in QuickBooks: no tax codes are sent. */
    public const OFF = 'off';

    /** US sales tax: each line is TAX or NON, and the invoice names the rate. */
    public const US = 'us';

    /** VAT or GST outside the US: each line names a tax code. Not checked against a live company. */
    public const GLOBAL = 'global';

    /**
     * @param array<string, mixed> $preferences QuickBooks Preferences
     *
     * @return Settings
     */
    public static function settings(array $preferences, string $country): array
    {
        $prefs = is_array($preferences['TaxPrefs'] ?? null) === true ? $preferences['TaxPrefs'] : [];
        $mode  = self::OFF;
        if (($prefs['UsingSalesTax'] ?? false) === true) {
            $mode = strtoupper(trim($country)) === 'US' ? self::US : self::GLOBAL;
        }

        return [
            'mode'      => $mode,
            // Intuit: PartnerTaxEnabled is true on automated sales tax, false while it is not set up.
            'automated' => ($prefs['PartnerTaxEnabled'] ?? false) === true,
            'rate'      => self::rateCode($prefs['TaxGroupCodeRef'] ?? null),
        ];
    }

    /**
     * The sales tax rates of each QuickBooks tax code, by code id, from a TaxCode query. A code
     * with no sales rates (exempt, zero-rated or purchases only) has none.
     *
     * @param array<int, array<string, mixed>> $taxCodes
     *
     * @return array<string, array<int, string>>
     */
    public static function codeRates(array $taxCodes): array
    {
        $codes = [];
        foreach ($taxCodes as $code) {
            $id = trim((string) ($code['Id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $details     = $code['SalesTaxRateList']['TaxRateDetail'] ?? [];
            $codes[$id]  = [];
            foreach (is_array($details) === true ? $details : [] as $detail) {
                $rate = is_array($detail) === true ? self::refValue($detail['TaxRateRef'] ?? null) : null;
                if ($rate !== null) {
                    $codes[$id][] = $rate;
                }
            }
        }

        return $codes;
    }

    /**
     * The tax code of one line. A US line is taxed only when both the product and the customer
     * are taxable. Outside the US the product's sales tax code comes first, then the customer's.
     *
     * @param Settings                  $settings
     * @param array<string, mixed>|null $customer QuickBooks Customer
     * @param array<string, mixed>|null $item     QuickBooks Item
     */
    public static function lineCode(array $settings, ?array $customer, ?array $item): ?string
    {
        if ($settings['mode'] === self::US) {
            $taxable = ($item['Taxable'] ?? false) === true && ($customer['Taxable'] ?? false) === true;

            return $taxable === true ? 'TAX' : 'NON';
        }
        if ($settings['mode'] === self::GLOBAL) {
            return self::refValue($item['SalesTaxCodeRef'] ?? null)
                ?? self::refValue($customer['DefaultTaxCodeRef'] ?? null);
        }

        return null;
    }

    /**
     * The US invoice rate: the customer's, then the company's. TAX and NON are line codes, and an
     * invoice sent either as its rate is not taxed, so they are skipped. Automated sales tax
     * chooses the rate from the address, and a rate sent would override it, so none is sent.
     *
     * @param Settings                  $settings
     * @param array<string, mixed>|null $customer
     */
    public static function invoiceRate(array $settings, ?array $customer): ?string
    {
        if ($settings['mode'] !== self::US || $settings['automated'] === true) {
            return null;
        }

        return self::rateCode($customer['DefaultTaxCodeRef'] ?? null) ?? self::rateCode(['value' => $settings['rate']]);
    }

    /**
     * Line codes by line position for the items the lines use, the invoice rate, and, outside the
     * US, GlobalTaxCalculation for a new invoice (Fleetbase amounts are before tax). An item
     * QuickBooks did not return is not taxable.
     *
     * @param Settings                            $settings
     * @param array<string, mixed>|null           $customer
     * @param array<string, array<string, mixed>> $items     QuickBooks items by id
     * @param array<int, string>                  $lineItems item id of each line
     *
     * @return Plan
     */
    public static function plan(array $settings, ?array $customer, array $items, array $lineItems): array
    {
        $lines = [];
        foreach ($lineItems as $itemId) {
            $lines[] = self::lineCode($settings, $customer, $items[$itemId] ?? []);
        }

        return [
            'lines'  => $lines,
            'rate'   => self::invoiceRate($settings, $customer),
            'global' => $settings['mode'] === self::GLOBAL ? 'TaxExcluded' : null,
        ];
    }

    private static function rateCode(mixed $ref): ?string
    {
        $value = self::refValue($ref);

        return in_array($value, ['TAX', 'NON'], true) === true ? null : $value;
    }

    private static function refValue(mixed $ref): ?string
    {
        $value = is_array($ref) === true ? trim((string) ($ref['value'] ?? '')) : '';

        return $value === '' ? null : $value;
    }
}
