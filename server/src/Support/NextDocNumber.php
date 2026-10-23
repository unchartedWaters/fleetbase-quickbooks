<?php

namespace Fleetbase\Quickbooks\Support;

class NextDocNumber
{
    /**
     * The next number in a sequence such as 1041 or INV-009.
     * The prefix is kept and the trailing digits are incremented.
     */
    public static function after(string $current): ?string
    {
        if (preg_match('/^(.*?)(\d+)$/', $current, $matches) !== 1) {
            return null;
        }

        $prefix = $matches[1];
        $digits = $matches[2];
        $next   = (string) ((int) $digits + 1);
        if (strlen($next) < strlen($digits)) {
            $next = str_pad($next, strlen($digits), '0', STR_PAD_LEFT);
        }

        return $prefix . $next;
    }

    /**
     * Whether $number comes after $other in the same sequence: the same prefix and a larger
     * trailing number. Numbers from different sequences are never later.
     */
    public static function isLater(string $number, string $other): bool
    {
        if (
            preg_match('/^(.*?)(\d+)$/', $number, $first) !== 1
            || preg_match('/^(.*?)(\d+)$/', $other, $second) !== 1
        ) {
            return false;
        }
        if ($first[1] !== $second[1]) {
            return false;
        }
        $a = ltrim($first[2], '0');
        $b = ltrim($second[2], '0');

        return strlen($a) !== strlen($b) ? strlen($a) > strlen($b) : strcmp($a, $b) > 0;
    }
}
