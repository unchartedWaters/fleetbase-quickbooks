<?php

namespace Fleetbase\Quickbooks\Support;

/**
 * Invoice tax worked out by Fleetbase (sync setting tax_source fleetbase): the tax on the
 * Fleetbase lines is put on the QuickBooks payload as an override. A US line is coded TAX or NON
 * by whether it has tax, and the invoice tax is TxnTaxDetail.TotalTax. Outside the US each line
 * keeps a QuickBooks tax code whose rates add up to its Fleetbase rate, and the tax of each rate
 * is sent as a TaxLine (Intuit: every rate of a code used on the invoice is sent). A line no code
 * fits fails the invoice, so QuickBooks never files tax under the wrong rate.
 *
 * @phpstan-import-type Settings from InvoiceTax
 *
 * @phpstan-type Rates array<string, array{percent: string, read_only: bool}>
 */
class FleetbaseTax
{
    /** Percents are compared in millionths of a percent, so 9.975 adds up exactly. */
    private const MICRO = 1000000;

    /**
     * The payload with Fleetbase's tax on it, or the error (a translation key) that stops the
     * invoice. The first sales lines of the payload are the Fleetbase items, in order; any after
     * them are lines only QuickBooks has, which keep their code and get no tax.
     *
     * @param array<string, mixed>    $payload
     * @param array<int, mixed>       $items     Fleetbase invoice items
     * @param Settings                $settings
     * @param array<int, string|null> $planCodes the QuickBooks product or customer code of each item
     * @param Rates                   $rates     QuickBooks tax rates by id, outside the US
     * @param array<string, mixed>    $remote    the QuickBooks invoice, empty for a new one
     *
     * @return array{payload: array<string, mixed>, error: string|null}
     */
    public static function apply(
        array $payload,
        array $items,
        array $settings,
        array $planCodes,
        array $rates,
        array $remote,
    ): array {
        if ($settings['mode'] === InvoiceTax::OFF) {
            return ['payload' => $payload, 'error' => null];
        }
        if (InvoiceMapper::taxInclusive($remote) === true) {
            return ['payload' => $payload, 'error' => Translation::encode('quickbooks.messages.invoice-tax-inclusive')];
        }
        $items = array_values(array_filter($items, 'is_array'));
        if ($settings['mode'] === InvoiceTax::US) {
            return self::unitedStates($payload, $items, $settings);
        }

        return self::global($payload, $items, $settings, $planCodes, $rates);
    }

    /**
     * The tax of the Fleetbase items, in minor units.
     *
     * @param array<int, mixed> $items
     */
    public static function total(array $items): int
    {
        $tax = 0;
        foreach ($items as $item) {
            $tax += is_array($item) === true ? (int) ($item['tax_amount'] ?? 0) : 0;
        }

        return $tax;
    }

    /**
     * A TaxLine of TxnTaxDetail: the tax of one QuickBooks rate.
     *
     * @return array<string, mixed>
     */
    public static function taxLine(string $rateId, string $percent, string $amount, string $net): array
    {
        return [
            'Amount'        => $amount,
            'DetailType'    => 'TaxLineDetail',
            'TaxLineDetail' => [
                'PercentBased'     => true,
                'TaxPercent'       => $percent,
                'TaxRateRef'       => ['value' => $rateId],
                'NetAmountTaxable' => $net,
            ],
        ];
    }

    /**
     * QuickBooks tax rates by id from a TaxRate query: the percent as QuickBooks holds it, and
     * whether QuickBooks refuses a changed amount for it (DisplayType ReadOnly). A rate with no
     * percent is left out.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return Rates
     */
    public static function rates(array $rows): array
    {
        $rates = [];
        foreach ($rows as $row) {
            $rateId  = trim((string) ($row['Id'] ?? ''));
            $percent = $row['RateValue'] ?? null;
            if ($rateId === '' || (is_string($percent) === false && is_int($percent) === false)) {
                continue;
            }
            if (self::micro((string) $percent) === null) {
                continue;
            }
            $rates[$rateId] = [
                'percent'   => (string) $percent,
                'read_only' => (string) ($row['DisplayType'] ?? '') === 'ReadOnly',
            ];
        }

        return $rates;
    }

    /**
     * @param array<string, mixed>             $payload
     * @param array<int, array<string, mixed>> $items
     * @param Settings                         $settings
     *
     * @return array{payload: array<string, mixed>, error: string|null}
     */
    private static function unitedStates(array $payload, array $items, array $settings): array
    {
        foreach (self::salesLines($payload, count($items)) as $position => $index) {
            $taxed = (int) ($items[$position]['tax_amount'] ?? 0) !== 0
                || InvoiceMapper::taxRate($items[$position]['tax_rate'] ?? 0) !== '0.00';
            $code  = $taxed === true ? 'TAX' : 'NON';

            $payload['Line'][$index]['SalesItemLineDetail']['TaxCodeRef'] = ['value' => $code];
        }
        $tax  = self::total($items);
        $rate = trim((string) ($payload['TxnTaxDetail']['TxnTaxCodeRef']['value'] ?? ''));
        if ($rate === '' && $settings['automated'] === false) {
            if ($tax !== 0) {
                $error = Translation::encode('quickbooks.messages.invoice-tax-no-rate-fleetbase');

                return ['payload' => $payload, 'error' => $error];
            }
            unset($payload['TxnTaxDetail']);

            return ['payload' => $payload, 'error' => null];
        }
        $detail                  = $rate === '' ? [] : ['TxnTaxCodeRef' => ['value' => $rate]];
        $payload['TxnTaxDetail'] = $detail + ['TotalTax' => Amounts::centsToDecimal($tax, self::currency($payload))];

        return ['payload' => $payload, 'error' => null];
    }

    /**
     * @param array<string, mixed>             $payload
     * @param array<int, array<string, mixed>> $items
     * @param Settings                         $settings
     * @param array<int, string|null>          $planCodes
     * @param Rates                            $rates
     *
     * @return array{payload: array<string, mixed>, error: string|null}
     */
    private static function global(array $payload, array $items, array $settings, array $planCodes, array $rates): array
    {
        $currency = self::currency($payload);
        $sums     = [];
        $fleet    = self::salesLines($payload, count($items));
        foreach ($fleet as $position => $index) {
            /** @var array<string, mixed> $line */
            $line   = $payload['Line'][$index];
            $chosen = self::chooseCode($line, $items[$position], $planCodes[$position] ?? null, $settings, $rates);
            if ($chosen['error'] !== null) {
                return ['payload' => $payload, 'error' => $chosen['error']];
            }
            $amount = Amounts::toMinorUnits((string) ($line['Amount'] ?? '0'), $currency);
            $tax    = (int) ($items[$position]['tax_amount'] ?? 0);
            self::addShares($sums, $settings['codes'][$chosen['code']] ?? [], $rates, $tax, $amount);

            $payload['Line'][$index]['SalesItemLineDetail']['TaxCodeRef'] = ['value' => $chosen['code']];
        }
        foreach (self::salesLines($payload, PHP_INT_MAX) as $index) {
            $code = trim((string) ($payload['Line'][$index]['SalesItemLineDetail']['TaxCodeRef']['value'] ?? ''));
            if (in_array($index, $fleet, true) === true || $code === '') {
                continue;
            }
            // A line only QuickBooks has gets no tax, but its rates are still sent.
            $amount = Amounts::toMinorUnits((string) ($payload['Line'][$index]['Amount'] ?? '0'), $currency);
            self::addShares($sums, $settings['codes'][$code] ?? [], $rates, 0, $amount);
        }
        $lines = [];
        foreach ($sums as $rateId => $sum) {
            $lines[] = self::taxLine(
                $rateId,
                $rates[$rateId]['percent'] ?? '0',
                Amounts::centsToDecimal($sum['tax'], $currency),
                Amounts::centsToDecimal($sum['net'], $currency)
            );
        }
        $payload['TxnTaxDetail'] = [
            'TotalTax' => Amounts::centsToDecimal(self::total($items), $currency),
            'TaxLine'  => $lines,
        ];

        return ['payload' => $payload, 'error' => null];
    }

    /**
     * The code a line is sent with: the one it has, else the product or customer code, whichever
     * first adds up to the line's Fleetbase rate. The error explains the first candidate.
     *
     * @param array<string, mixed> $line
     * @param array<string, mixed> $item
     * @param Settings             $settings
     * @param Rates                $rates
     *
     * @return array{code: string, error: string|null}
     */
    private static function chooseCode(array $line, array $item, ?string $planned, array $settings, array $rates): array
    {
        $held       = trim((string) ($line['SalesItemLineDetail']['TaxCodeRef']['value'] ?? ''));
        $candidates = array_values(array_unique(array_filter(
            [$held, trim((string) $planned)],
            static fn (string $code): bool => $code !== ''
        )));
        $rate       = InvoiceMapper::taxRate($item['tax_rate'] ?? 0);
        $name       = (string) ($item['description'] ?? '');
        $error      = Translation::encode('quickbooks.messages.invoice-tax-no-code', ['line' => $name]);
        foreach ($candidates as $position => $code) {
            $problem = self::codeProblem($code, $rate, (int) ($item['tax_amount'] ?? 0), $settings, $rates, $name);
            if ($problem === null) {
                return ['code' => $code, 'error' => null];
            }
            if ($position === 0) {
                $error = $problem;
            }
        }

        return ['code' => '', 'error' => $error];
    }

    /**
     * Why a code cannot carry a line's Fleetbase rate, or null when it can.
     *
     * @param Settings $settings
     * @param Rates    $rates
     */
    private static function codeProblem(
        string $code,
        string $rate,
        int $tax,
        array $settings,
        array $rates,
        string $name,
    ): ?string {
        $ids = $settings['codes'][$code] ?? null;
        if ($ids === null) {
            return Translation::encode('quickbooks.messages.invoice-tax-rates-unread', ['line' => $name]);
        }
        if (in_array($code, $settings['compound'] ?? [], true) === true) {
            return Translation::encode('quickbooks.messages.invoice-tax-compound', ['line' => $name]);
        }
        $sum = 0;
        foreach ($ids as $rateId) {
            $micro = isset($rates[$rateId]) === true ? self::micro($rates[$rateId]['percent']) : null;
            if ($micro === null) {
                return Translation::encode('quickbooks.messages.invoice-tax-rates-unread', ['line' => $name]);
            }
            if ($rates[$rateId]['read_only'] === true) {
                return Translation::encode('quickbooks.messages.invoice-tax-read-only', ['line' => $name]);
            }
            $sum += $micro;
        }
        $percent = self::percentText($sum);
        if ($sum % (self::MICRO / 100) !== 0) {
            return Translation::encode(
                'quickbooks.messages.invoice-tax-rate-precision',
                ['line' => $name, 'code' => $percent]
            );
        }
        if ($sum !== self::micro($rate) || ($sum === 0 && $tax !== 0)) {
            return Translation::encode(
                'quickbooks.messages.invoice-tax-rate-mismatch',
                ['line' => $name, 'rate' => $rate, 'code' => $percent]
            );
        }

        return null;
    }

    /**
     * Add one line to the tax and taxable amount of each rate of its code. The line tax is split
     * by percent, the rounding left on the last rate.
     *
     * @param array<string, array{tax: int, net: int}> $sums
     * @param array<int, string>                       $ids
     * @param Rates                                    $rates
     */
    private static function addShares(array &$sums, array $ids, array $rates, int $tax, int $amount): void
    {
        $percents = [];
        foreach ($ids as $rateId) {
            $percents[$rateId] = self::micro($rates[$rateId]['percent'] ?? '') ?? 0;
        }
        $base = array_sum($percents);
        $left = $tax;
        $last = array_key_last($percents);
        foreach ($percents as $rateId => $percent) {
            $share = $rateId === $last || $base === 0 ? $left : self::share($tax, $percent, $base);
            $left -= $share;
            $sums[$rateId] ??= ['tax' => 0, 'net' => 0];
            $sums[$rateId]['tax'] += $share;
            $sums[$rateId]['net'] += $amount;
        }
    }

    /**
     * Payload indexes of the first $count sales lines, by position.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<int, int>
     */
    private static function salesLines(array $payload, int $count): array
    {
        $found = [];
        foreach (is_array($payload['Line'] ?? null) === true ? $payload['Line'] : [] as $index => $line) {
            if (count($found) >= $count) {
                break;
            }
            if (is_array($line) === true && (string) ($line['DetailType'] ?? '') === 'SalesItemLineDetail') {
                $found[] = (int) $index;
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function currency(array $payload): string
    {
        return trim((string) ($payload['CurrencyRef']['value'] ?? ''));
    }

    /**
     * A percent such as "9.975" in millionths, or null when it is not a plain decimal.
     */
    private static function micro(string $percent): ?int
    {
        if (preg_match('/^\s*(\d{1,3})(?:\.(\d{1,6}))?\s*$/', $percent, $parts) !== 1) {
            return null;
        }

        return (int) $parts[1] * self::MICRO + (int) str_pad($parts[2] ?? '', 6, '0');
    }

    private static function percentText(int $micro): string
    {
        $text = intdiv($micro, self::MICRO) . '.' . str_pad((string) ($micro % self::MICRO), 6, '0', STR_PAD_LEFT);

        return rtrim(rtrim($text, '0'), '.');
    }

    /**
     * $tax times $part over $base, rounded half away from zero, in integers.
     */
    private static function share(int $tax, int $part, int $base): int
    {
        $product = $tax * $part;
        $sign    = $product < 0 ? -1 : 1;

        return $sign * intdiv(2 * abs($product) + $base, 2 * $base);
    }
}
