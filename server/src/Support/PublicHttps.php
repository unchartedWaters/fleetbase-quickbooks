<?php

namespace Fleetbase\Quickbooks\Support;

/**
 * Public https check for QuickBooks redirect and receiver URLs.
 * IP literals are normalized, including short, octal, decimal, and hexadecimal
 * forms. 6to4 and NAT64 addresses are rejected when the embedded IPv4 is not
 * public. A hostname is resolved, and the URL is rejected when DNS fails or any
 * address is not a public unicast address.
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
        $host = trim($host, '[]');
        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }
        if (preg_match('/[^\x00-\x7F]/', $host) === 1) {
            if (!function_exists('idn_to_ascii')) {
                return true;
            }
            $ascii = idn_to_ascii($host, IDNA_DEFAULT);
            if (!is_string($ascii) || $ascii === '') {
                return true;
            }
            $host = $ascii;
        }
        $host = strtolower($host);
        if ($host === '' || self::hostnameIsLoopback($host)) {
            return true;
        }
        if (self::looksLikeIpv4Literal($host)) {
            $ipv4 = self::ipv4FromLiteral($host);

            return $ipv4 === null || self::ipv4IsInternal($ipv4);
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return self::ipv6IsInternal($host);
        }
        if (str_contains($host, ':')) {
            return true;
        }

        return !self::hostnameResolvesToPublic($host);
    }

    private static function hostnameIsLoopback(string $hostname): bool
    {
        return in_array($hostname, ['localhost', '127.0.0.1', '::1'], true);
    }

    private static function looksLikeIpv4Literal(string $host): bool
    {
        return preg_match('/^(?:0x[0-9a-f]+|\d+)(?:\.(?:0x[0-9a-f]+|\d+))*$/', $host) === 1;
    }

    /**
     * inet_aton forms: a, a.b, a.b.c, and a.b.c.d, with decimal, octal, or hex parts.
     */
    private static function ipv4FromLiteral(string $host): ?string
    {
        if (preg_match('/^(?:0x[0-9a-f]+|\d+)(?:\.(?:0x[0-9a-f]+|\d+)){0,3}$/', $host) !== 1) {
            return null;
        }
        $parts   = explode('.', $host);
        $count   = count($parts);
        $numbers = [];
        foreach ($parts as $index => $part) {
            $parsed = self::parseIpv4Component($part);
            if ($parsed === null) {
                return null;
            }
            $bits = $index === $count - 1 ? 8 * (5 - $count) : 8;
            $max  = (2 ** $bits) - 1;
            if ($parsed > $max) {
                return null;
            }
            $numbers[] = $parsed;
        }
        $value = match ($count) {
            1       => $numbers[0],
            2       => ($numbers[0] * 16777216) + $numbers[1],
            3       => ($numbers[0] * 16777216) + ($numbers[1] * 65536) + $numbers[2],
            default => ($numbers[0] * 16777216) + ($numbers[1] * 65536) + ($numbers[2] * 256) + $numbers[3],
        };

        $formatted = long2ip($value & 0xFFFFFFFF);

        return is_string($formatted) ? $formatted : null;
    }

    private static function parseIpv4Component(string $part): ?int
    {
        if ($part === '' || $part === '0x') {
            return null;
        }
        if (preg_match('/^0x([0-9a-f]+)$/', $part, $matches) === 1) {
            if (strlen($matches[1]) > 8) {
                return null;
            }
            $value = hexdec($matches[1]);
            if (!is_int($value) || $value < 0) {
                return null;
            }

            return $value;
        }
        if ($part[0] === '0') {
            if (strlen($part) > 11 || preg_match('/^[0-7]+$/', $part) !== 1) {
                return null;
            }

            return intval($part, 8);
        }
        if (preg_match('/^[1-9][0-9]*$/', $part) !== 1 || strlen($part) > 10) {
            return null;
        }
        $value = (int) $part;
        if ((string) $value !== $part) {
            return null;
        }

        return $value;
    }

    private static function ipv4IsInternal(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return true;
        }
        $value = (int) sprintf('%u', $long);
        $cidrs = [
            ['0.0.0.0', 8],
            ['10.0.0.0', 8],
            ['100.64.0.0', 10],
            ['127.0.0.0', 8],
            ['169.254.0.0', 16],
            ['172.16.0.0', 12],
            ['192.0.0.0', 24],
            ['192.0.2.0', 24],
            ['192.168.0.0', 16],
            ['198.18.0.0', 15],
            ['198.51.100.0', 24],
            ['203.0.113.0', 24],
            ['224.0.0.0', 4],
            ['240.0.0.0', 4],
        ];
        foreach ($cidrs as [$network, $bits]) {
            if (self::ipv4InCidr($value, $network, $bits)) {
                return true;
            }
        }

        return false;
    }

    private static function ipv4InCidr(int $value, string $network, int $bits): bool
    {
        $base = ip2long($network);
        if ($base === false || $bits < 1 || $bits > 32) {
            return true;
        }
        $base = (int) sprintf('%u', $base);
        $mask = (int) ((0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF);

        return ($value & $mask) === ($base & $mask);
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
        if (str_starts_with($packed, str_repeat("\x00", 12))) {
            $ipv4 = inet_ntop(substr($packed, 12));

            return !is_string($ipv4) || self::ipv4IsInternal($ipv4);
        }
        // 6to4 (2002::/16) stores the IPv4 address in bits 16-47.
        if ($packed[0] === "\x20" && $packed[1] === "\x02") {
            $ipv4 = inet_ntop(substr($packed, 2, 4));

            return !is_string($ipv4) || self::ipv4IsInternal($ipv4);
        }
        // NAT64 well-known prefix (64:ff9b::/96) stores the IPv4 address in the last 32 bits.
        $nat64 = "\x00\x64\xff\x9b" . str_repeat("\x00", 8);
        if (str_starts_with($packed, $nat64)) {
            $ipv4 = inet_ntop(substr($packed, 12));

            return !is_string($ipv4) || self::ipv4IsInternal($ipv4);
        }
        $first  = ord($packed[0]);
        $second = ord($packed[1]);
        if ($first === 0xFF) {
            return true;
        }
        if ($first === 0xFE && (($second & 0xC0) === 0x80 || ($second & 0xC0) === 0xC0)) {
            return true;
        }
        if (($first & 0xFE) === 0xFC) {
            return true;
        }

        return $packed[0] === "\x20"
            && $packed[1] === "\x01"
            && $packed[2] === "\x0d"
            && $packed[3] === "\xb8";
    }

    private static function addressIsInternal(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return self::ipv4IsInternal($ip);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return self::ipv6IsInternal($ip);
        }

        return true;
    }

    private static function hostnameResolvesToPublic(string $host): bool
    {
        if (strlen($host) > 253 || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $host) !== 1) {
            return false;
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records) || $records === []) {
            return false;
        }
        $sawAddress = false;
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $ip = null;
            if (isset($record['ip']) && is_string($record['ip'])) {
                $ip = $record['ip'];
            } elseif (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $ip = $record['ipv6'];
            }
            if ($ip === null || $ip === '') {
                continue;
            }
            $sawAddress = true;
            if (self::addressIsInternal($ip)) {
                return false;
            }
        }

        return $sawAddress;
    }
}
