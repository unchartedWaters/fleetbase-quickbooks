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
}
