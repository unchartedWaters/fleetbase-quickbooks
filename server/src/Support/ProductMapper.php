<?php

namespace Fleetbase\Quickbooks\Support;

use Ramsey\Uuid\Uuid;

/**
 * Maps a Storefront product, or a Fleet-Ops fee, onto a QuickBooks product (Item). The sync is
 * one way: Fleetbase changes are sent and nothing is copied back.
 */
class ProductMapper
{
    public const NAME_MAX_LENGTH = 100;

    public const DESCRIPTION_MAX_LENGTH = 4000;

    public const SKU_MAX_LENGTH = 100;

    /**
     * Fixed namespace of the product link ids, so a name keeps its link across runs.
     */
    private const ITEM_NAMESPACE = '6f3f9d4e-2c55-4a0e-9a57-0b6c1f2b8e41';

    /**
     * Added to the name of a product Fleetbase makes when QuickBooks already uses the name.
     */
    public const SUFFIX = 'Fleetbase';

    /**
     * The QuickBooks product lines fall back to. A Fleetbase product of this name uses it too.
     */
    public const SERVICE_ITEM_NAME = 'Fleetbase service';

    /**
     * The fee lines Fleet-Ops (ServiceRate) and Ledger (InvoiceService) write, by their exact label.
     */
    private const FEES = [
        'base fee'             => 'Base Fee',
        'service fee'          => 'Service Fee',
        'cash on delivery fee' => 'Cash on delivery fee',
        'peak hours fee'       => 'Peak hours fee',
        'delivery fee'         => 'Delivery fee',
    ];

    /**
     * The name QuickBooks gets: no ":" (it marks a sub-product), tab or line break, one space
     * between words, at most 100 characters. A product without a name is named by its id.
     */
    public static function name(?string $name, string $publicId = ''): string
    {
        $name = trim((string) preg_replace('/[\s:]+/u', ' ', (string) $name));
        if ($name === '') {
            $name = trim('Product ' . trim($publicId));
        }

        return rtrim(mb_substr($name, 0, self::NAME_MAX_LENGTH));
    }

    /**
     * What two names are compared by: QuickBooks names ignore case.
     */
    public static function key(string $name): string
    {
        return mb_strtolower(self::name($name));
    }

    /**
     * The QuickBooks product a fee line goes to, or null when the line is not a known fee.
     */
    public static function feeName(string $description): ?string
    {
        $key = self::key($description);
        if (isset(self::FEES[$key]) === true) {
            return self::FEES[$key];
        }
        if (str_ends_with($key, ' parcel fee') === true) {
            return 'Parcel fee';
        }
        if (preg_match('/distance charge \(/u', $key) === 1) {
            return 'Distance charge';
        }

        return null;
    }

    /**
     * The product a Ledger line's meta names (meta.product, an object with a name), as stored. A
     * pricing extension names the product of each line it writes this way. Null when there is
     * none or its name is blank.
     *
     * @return array<string, mixed>|null
     */
    public static function metaProduct(mixed $meta): ?array
    {
        $product = is_array($meta) === true ? ($meta['product'] ?? null) : null;
        $name    = is_array($product) === true ? ($product['name'] ?? null) : null;
        $blank   = is_string($name) === false || trim((string) preg_replace('/[\s:]+/u', ' ', $name)) === '';

        return $blank === true ? null : $product;
    }

    /**
     * The name of the product a line names (metaProduct), or null.
     *
     * @param array<string, mixed> $item a Ledger line as the directory reads it
     */
    public static function lineProduct(array $item): ?string
    {
        $product = self::metaProduct(['product' => $item['product'] ?? null]);

        return $product === null ? null : self::name((string) $product['name']);
    }

    /**
     * The fixed local id of the link of one product name of a company. Every Storefront product
     * and fee of that name shares the one QuickBooks product.
     */
    public static function itemUuid(string $companyUuid, string $name): string
    {
        return Uuid::uuid5(self::ITEM_NAMESPACE, $companyUuid . '|' . self::key($name))->toString();
    }

    /**
     * The fee product a name stands for, whatever its case, or null when it is not one: one of the
     * fee labels, "Parcel fee" or "Distance charge".
     */
    public static function feeProduct(string $name): ?string
    {
        $key = self::key($name);
        foreach (array_merge(array_values(self::FEES), ['Parcel fee', 'Distance charge']) as $fee) {
            if (mb_strtolower($fee) === $key) {
                return $fee;
            }
        }

        return null;
    }

    /**
     * A name with " (Fleetbase)" added, for a name QuickBooks already uses for a product Fleetbase
     * may not take. The name is cut first so the whole name still fits.
     */
    public static function suffixed(string $name): string
    {
        $suffix = ' (' . self::SUFFIX . ')';
        $room   = max(0, self::NAME_MAX_LENGTH - mb_strlen($suffix));

        return rtrim(mb_substr(self::name($name), 0, $room)) . $suffix;
    }

    /**
     * The name several products of one name go by: the one they all have, else the first in
     * order, so every run picks the same one. Null for no products.
     *
     * @param array<int, array<string, mixed>> $products
     */
    public static function sharedName(array $products): ?string
    {
        $names = self::productNames($products);
        if ($names === []) {
            return null;
        }
        sort($names, SORT_STRING);

        return $names[0];
    }

    /**
     * A new QuickBooks product. Its type is set once, here: QuickBooks does not change it later.
     * It posts to the income account of the service item and is taxed like it, so a product
     * line is taxed as the line it replaces. A fee ($products empty) has no text or price.
     *
     * @param array<int, array<string, mixed>> $products
     * @param array<string, mixed>             $serviceItem
     *
     * @return array<string, mixed>
     */
    public function create(string $name, array $products, string $homeCurrency, array $serviceItem): array
    {
        $services = array_filter(
            $products,
            static fn (array $product): bool => ($product['is_service'] ?? false) === true
        );
        $goods    = $products !== [] && $services === [];
        $payload  = [
            'Name' => self::name($name),
            'Type' => $goods === true ? 'NonInventory' : 'Service',
        ];
        foreach ($this->agreedFields($products, $homeCurrency) as $field => $value) {
            if ($value !== '') {
                $payload[$field] = $value;
            }
        }
        $account = trim((string) ($serviceItem['IncomeAccountRef']['value'] ?? ''));
        if ($account !== '') {
            $payload['IncomeAccountRef'] = ['value' => $account];
        }
        if (is_bool($serviceItem['Taxable'] ?? null) === true) {
            $payload['Taxable'] = $serviceItem['Taxable'];
        }
        $code = trim((string) ($serviceItem['SalesTaxCodeRef']['value'] ?? ''));
        if ($code !== '') {
            $payload['SalesTaxCodeRef'] = ['value' => $code];
        }

        return $payload;
    }

    /**
     * The fields to send so the QuickBooks product shows the Fleetbase ones: the name (a change
     * of case included, the suffix kept) when the products agree on it, and each of the text,
     * sku and price they agree on, a clear sent as empty. The type is never sent, and a fee
     * ($products empty) is never changed.
     *
     * @param array<int, array<string, mixed>> $products
     * @param array<string, mixed>             $remote
     *
     * @return array<string, mixed>
     */
    public function changes(string $name, array $products, array $remote, string $homeCurrency): array
    {
        if ($products === []) {
            return [];
        }
        $changes  = [];
        $current  = trim((string) ($remote['Name'] ?? ''));
        $names    = array_values(array_unique(self::productNames($products)));
        $wanted   = count($names) === 1 ? $names[0] : null;
        $suffixed = str_ends_with($current, ' (' . self::SUFFIX . ')');
        if ($wanted !== null && $suffixed === true) {
            $wanted = self::suffixed($wanted);
        }
        if ($wanted === null && self::key($current) !== self::key($name) && $suffixed === false) {
            $wanted = self::name($name);
        }
        if ($wanted !== null && $wanted !== $current) {
            $changes['Name'] = $wanted;
        }
        foreach ($this->agreedFields($products, $homeCurrency) as $field => $value) {
            $remoteValue = trim((string) ($remote[$field] ?? ''));
            $differs     = $field === 'UnitPrice'
                ? Amounts::toMinorUnits($value) !== Amounts::toMinorUnits($remoteValue)
                : $value !== $remoteValue;
            if ($differs === true) {
                $changes[$field] = $value;
            }
        }

        return $changes;
    }

    /**
     * @param array<int, array<string, mixed>> $products
     *
     * @return array<int, string>
     */
    private static function productNames(array $products): array
    {
        return array_map(
            static fn (array $product): string => self::name(
                (string) ($product['name'] ?? ''),
                (string) ($product['public_id'] ?? '')
            ),
            $products
        );
    }

    /**
     * Description, Sku and UnitPrice as QuickBooks holds them, each only when every product
     * has the same value. A product priced in another currency than the QuickBooks home currency
     * has no price to send, so the price is not agreed.
     *
     * @param array<int, array<string, mixed>> $products
     *
     * @return array<string, string>
     */
    private function agreedFields(array $products, string $homeCurrency): array
    {
        $agreed = null;
        foreach ($products as $product) {
            $fields = $this->fields($product, $homeCurrency);
            if ($agreed === null) {
                $agreed = $fields;
                continue;
            }
            foreach ($agreed as $field => $value) {
                if (($fields[$field] ?? null) !== $value) {
                    unset($agreed[$field]);
                }
            }
        }

        return $agreed ?? [];
    }

    /**
     * Description, Sku and UnitPrice of one product. The price is left out unless the product
     * is priced in the QuickBooks home currency (or has no currency).
     *
     * @param array<string, mixed> $product
     *
     * @return array<string, string>
     */
    private function fields(array $product, string $homeCurrency): array
    {
        $fields = [
            'Description' => mb_substr(trim((string) ($product['description'] ?? '')), 0, self::DESCRIPTION_MAX_LENGTH),
            'Sku'         => mb_substr(trim((string) ($product['sku'] ?? '')), 0, self::SKU_MAX_LENGTH),
        ];
        $currency = strtoupper(trim((string) ($product['currency'] ?? '')));
        $home     = strtoupper(trim($homeCurrency));
        if ($currency === '' || $currency === $home) {
            $fields['UnitPrice'] = Amounts::centsToDecimal((int) ($product['price'] ?? 0), $home);
        }

        return $fields;
    }
}
