<?php

namespace Fleetbase\Quickbooks\Support;

/**
 * Stable hash of the fields a sync compares. Money inside the array is integer minor units.
 */
class ContentHash
{
    /**
     * @param array<string, mixed> $canonical
     */
    public static function of(array $canonical): string
    {
        return hash('sha256', json_encode(self::sort($canonical), JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function sort(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item) === true) {
                $value[$key] = self::sort($item);
            }
        }
        if (array_is_list($value) === true) {
            return $value;
        }
        ksort($value);

        return $value;
    }
}
