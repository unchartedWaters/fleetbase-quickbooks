<?php

namespace Fleetbase\Quickbooks\Support;

/**
 * The public https check used when QuickBooks settings are saved.
 * The host is taken from the URL. This does not resolve the name or request it.
 */
class PublicHttps
{
    public static function isPublicHttpsUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || !str_starts_with(strtolower($url), 'https://')) {
            return false;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $host = $parts['host'] ?? null;
        if (!is_string($host) || $host === '') {
            return false;
        }

        return !self::hostIsInternal($host);
    }

    private static function hostIsInternal(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || self::hostnameIsLoopback($host)) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return self::ipv4IsInternal($host);
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return self::ipv6IsInternal($host);
        }

        return false;
    }

    private static function hostnameIsLoopback(string $hostname): bool
    {
        $hostname = strtolower(trim($hostname, '[]'));

        return in_array($hostname, ['localhost', '127.0.0.1', '::1'], true);
    }

    private static function ipv4IsInternal(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return true;
        }
        $value  = (int) sprintf('%u', $long);
        $ranges = [
            ['10.0.0.0', '10.255.255.255'],
            ['127.0.0.0', '127.255.255.255'],
            ['169.254.0.0', '169.254.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.168.0.0', '192.168.255.255'],
        ];
        foreach ($ranges as [$start, $end]) {
            $from = (int) sprintf('%u', ip2long($start));
            $to   = (int) sprintf('%u', ip2long($end));
            if ($value >= $from && $value <= $to) {
                return true;
            }
        }

        return false;
    }

    private static function ipv6IsInternal(string $ip): bool
    {
        $packed = inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return true;
        }
        $mapped = str_repeat("\x00", 10) . "\xff\xff";
        if (str_starts_with($packed, $mapped)) {
            $ipv4 = inet_ntop(substr($packed, 12));

            return !is_string($ipv4) || self::ipv4IsInternal($ipv4);
        }
        if ($packed === inet_pton('::1')) {
            return true;
        }
        $first  = ord($packed[0]);
        $second = ord($packed[1]);
        // fe80::/10 is link-local. fc00::/7 is the private unique-local range.
        if ($first === 0xFE && ($second & 0xC0) === 0x80) {
            return true;
        }

        return ($first & 0xFE) === 0xFC;
    }
}
