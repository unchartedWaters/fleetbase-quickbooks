<?php

use Fleetbase\Quickbooks\Support\PublicHttps;
use Fleetbase\Quickbooks\Support\SecretCipher;

/*
 * Characterization tests for internal-address detection and legacy secret decryption.
 * Every URL here is an IP literal or is rejected before any DNS lookup.
 */

function sctPrivate(string $method, mixed ...$args): mixed
{
    $reflection = new ReflectionMethod(PublicHttps::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke(null, ...$args);
}

function sctLegacyBlob(string $plain, ?string $appKey = null, ?string $iv = null): string
{
    $configured = $appKey ?? (string) config('app.key');
    if (str_starts_with($configured, 'base64:') === true) {
        $configured = base64_decode(substr($configured, 7), true);
    }
    $key = substr(hash('sha256', $configured, true), 0, 32);
    $iv ??= random_bytes(16);

    return base64_encode($iv . openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv));
}

test('ipv4 literals in short, octal, hex and decimal forms normalize to dotted quads', function () {
    $cases = [
        '8.8.8.8'          => '8.8.8.8',
        '134744072'        => '8.8.8.8',
        '0x08080808'       => '8.8.8.8',
        '010.010.010.010'  => '8.8.8.8',
        '8.0x80808'        => '8.8.8.8',
        '8.8.2056'         => '8.8.8.8',
        '127.1'            => '127.0.0.1',
        '127.0.1'          => '127.0.0.1',
        '0x7f.1'           => '127.0.0.1',
        '0177.1'           => '127.0.0.1',
        '0x7f000001'       => '127.0.0.1',
        '2130706433'       => '127.0.0.1',
        '127.000.000.001'  => '127.0.0.1',
        '4294967295'       => '255.255.255.255',
        '1.16777215'       => '1.255.255.255',
        '0'                => '0.0.0.0',
        '00000000000'      => '0.0.0.0',
        '0xffffffff'       => '255.255.255.255',
        '1.2.65535'        => '1.2.255.255',
    ];

    foreach ($cases as $literal => $expected) {
        expect(sctPrivate('ipv4FromLiteral', $literal))->toBe($expected, $literal);
    }
});

test('ipv4 literals that overflow or are malformed do not normalize', function () {
    foreach ([
        '4294967296',
        '0x100000000',
        '0x000000001',
        '256.0.0.1',
        '1.2.3.256',
        '1.16777216',
        '1.2.65536',
        '08.8.8.8',
        '09',
        '000000000000',
        '1.2.3.4.5',
        '01234567890',
        '99999999999',
        '0x',
        '',
    ] as $literal) {
        expect(sctPrivate('ipv4FromLiteral', $literal))->toBeNull($literal);
    }
});

test('odd ipv4 encodings of internal addresses are rejected and of public addresses are accepted', function () {
    foreach ([
        'https://0X7F000001/callback',
        'https://0x7f.1/callback',
        'https://0177.1/callback',
        'https://012.0.0.1/callback',
        'https://0xa.0x0.0x0.0x1/callback',
        'https://167772161/callback',
        'https://3232235777/callback',
        'https://0xc0a80101/callback',
        'https://0300.0250.1.1/callback',
        'https://2852039166/callback',
        'https://0xa9fea9fe/callback',
        'https://169.254.43518/callback',
        'https://4294967295/callback',
        'https://0/callback',
        'https://4294967296/callback',
        'https://0x100000000/callback',
        'https://1.16777216/callback',
        'https://256.0.0.1/callback',
        'https://08.8.8.8/callback',
    ] as $url) {
        expect(PublicHttps::isPublicHttpsUrl($url))->toBeFalse($url);
    }

    foreach ([
        'https://134744072/callback',
        'https://0x08080808/callback',
        'https://010.010.010.010/callback',
        'https://8.0x80808/callback',
        'https://8.8.2056/callback',
        'https://1.16777215/callback',
    ] as $url) {
        expect(PublicHttps::isPublicHttpsUrl($url))->toBeTrue($url);
    }
});

test('ipv4 internal range boundaries', function () {
    $internal = [
        '0.0.0.1', '0.255.255.255',
        '10.0.0.0', '10.255.255.255',
        '100.64.0.0', '100.127.255.255',
        '127.0.0.0', '127.255.255.255',
        '169.254.0.0', '169.254.255.255',
        '172.16.0.0', '172.31.255.255',
        '192.0.0.0', '192.0.0.255',
        '192.0.2.0', '192.0.2.255',
        '192.168.0.0', '192.168.255.255',
        '198.18.0.0', '198.19.255.255',
        '198.51.100.0', '198.51.100.255',
        '203.0.113.0', '203.0.113.255',
        '224.0.0.0', '239.255.255.255',
        '240.0.0.0', '255.255.255.255',
    ];
    $public = [
        '1.0.0.0', '9.255.255.255', '11.0.0.0',
        '100.63.255.255', '100.128.0.0',
        '126.255.255.255', '128.0.0.0',
        '169.253.255.255', '169.255.0.0',
        '172.15.255.255', '172.32.0.0',
        '191.255.255.255', '192.0.1.0', '192.0.3.0',
        '192.167.255.255', '192.169.0.0',
        '198.17.255.255', '198.20.0.0',
        '198.51.99.255', '198.51.101.0',
        '203.0.112.255', '203.0.114.0',
        '223.255.255.255',
    ];

    foreach ($internal as $ip) {
        expect(sctPrivate('ipv4IsInternal', $ip))->toBeTrue($ip)
            ->and(PublicHttps::isPublicHttpsUrl('https://' . $ip . '/callback'))->toBeFalse($ip);
    }
    foreach ($public as $ip) {
        expect(sctPrivate('ipv4IsInternal', $ip))->toBeFalse($ip)
            ->and(PublicHttps::isPublicHttpsUrl('https://' . $ip . '/callback'))->toBeTrue($ip);
    }
});

test('ipv6 internal ranges are rejected at their edges and neighbours outside them are accepted', function () {
    $internal = [
        // loopback and unspecified
        '::1', '::', '0:0:0:0:0:0:0:1',
        // link-local fe80::/10
        'fe80::', 'fe80::1', 'FE80::1', 'febf:ffff:ffff:ffff:ffff:ffff:ffff:ffff',
        // site-local fec0::/10
        'fec0::', 'fec0::1', 'feff:ffff:ffff:ffff:ffff:ffff:ffff:ffff',
        // unique local fc00::/7
        'fc00::', 'fc00::1', 'fd00::1', 'fdff:ffff:ffff:ffff:ffff:ffff:ffff:ffff',
        // documentation 2001:db8::/32
        '2001:db8::', '2001:db8::1', '2001:db8:ffff:ffff:ffff:ffff:ffff:ffff',
        // multicast ff00::/8
        'ff00::', 'ff02::1', 'ff05::2', 'ff0e::1', 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff',
    ];
    $public = [
        '2606:4700:4700::1111',
        '2001:4860:4860::8888',
        '2001:db7:ffff:ffff:ffff:ffff:ffff:ffff',
        '2001:db9::',
        'fbff:ffff:ffff:ffff:ffff:ffff:ffff:ffff',
    ];

    foreach ($internal as $ip) {
        expect(sctPrivate('ipv6IsInternal', $ip))->toBeTrue($ip)
            ->and(PublicHttps::isPublicHttpsUrl('https://[' . $ip . ']/callback'))->toBeFalse($ip);
    }
    foreach ($public as $ip) {
        expect(sctPrivate('ipv6IsInternal', $ip))->toBeFalse($ip)
            ->and(PublicHttps::isPublicHttpsUrl('https://[' . $ip . ']/callback'))->toBeTrue($ip);
    }
    expect(sctPrivate('ipv6IsInternal', 'not-an-address'))->toBeTrue();
});

test('ipv6 forms that embed ipv4 follow the embedded address', function () {
    $internal = [
        // IPv4-mapped ::ffff:0:0/96
        '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:172.16.0.1', '::ffff:192.168.1.1',
        '::ffff:169.254.169.254', '::ffff:100.64.0.1', '::ffff:0.0.0.0', '::ffff:224.0.0.1',
        '::ffff:255.255.255.255', '0:0:0:0:0:ffff:7f00:1', '::FFFF:7F00:1',
        // IPv4-compatible ::/96
        '::127.0.0.1', '::10.0.0.1', '::192.168.0.1', '::169.254.169.254', '::0.0.0.2',
        '::a00:1', '::c0a8:101',
        // 6to4 2002::/16
        '2002:7f00:1::1', '2002:a00:1:2:3:4:5:6', '2002:c0a8:101::', '2002:ffff:ffff::',
        // NAT64 64:ff9b::/96
        '64:ff9b::127.0.0.1', '64:ff9b::10.0.0.1', '64:ff9b::192.168.1.1', '64:ff9b::ffff:ffff',
    ];
    $public = [
        '::ffff:8.8.8.8', '::ffff:808:808', '::ffff:1.1.1.1', '::ffff:100.128.0.1', '::ffff:172.32.0.1',
        '::8.8.8.8', '::808:808', '::1.1.1.1',
        '2002:808:808::1', '2002:101:101:ffff::', '2002:ac20:1::',
        '64:ff9b::8.8.8.8', '64:ff9b::808:808', '64:ff9b::1.1.1.1',
    ];

    foreach ($internal as $ip) {
        expect(sctPrivate('ipv6IsInternal', $ip))->toBeTrue($ip)
            ->and(PublicHttps::isPublicHttpsUrl('https://[' . $ip . ']/callback'))->toBeFalse($ip);
    }
    foreach ($public as $ip) {
        expect(sctPrivate('ipv6IsInternal', $ip))->toBeFalse($ip)
            ->and(PublicHttps::isPublicHttpsUrl('https://[' . $ip . ']/callback'))->toBeTrue($ip);
    }
});

test('ipv6 local-use nat64, siit, teredo and fe00::/9 addresses are internal', function () {
    // NAT64 local-use 64:ff9b:1::/48, SIIT ::ffff:0:0:0/96 and Teredo 2001::/32 can carry
    // a private IPv4 address, and fe00::/9 is reserved. None is a public unicast target.
    foreach ([
        '64:ff9b:1::',
        '64:ff9b:1::a00:1',
        '64:ff9b:1:ffff:ffff:ffff:ffff:ffff',
        '::ffff:0:0:0',
        '::ffff:0:a00:1',
        '::ffff:0:808:808',
        '2001::',
        '2001::a00:1',
        '2001:0:ffff:ffff:ffff:ffff:ffff:ffff',
        'fe00::',
        'fe00::1',
        'fe40::1',
        'fe7f:ffff:ffff:ffff:ffff:ffff:ffff:ffff',
    ] as $ip) {
        expect(sctPrivate('ipv6IsInternal', $ip))->toBeTrue($ip)
            ->and(PublicHttps::isPublicHttpsUrl('https://[' . $ip . ']/callback'))->toBeFalse($ip);
    }
    // Neighbours just outside those ranges stay public.
    foreach ([
        '64:ff9a:ffff:ffff:ffff:ffff:ffff:ffff',
        '::fffe:0:808:808',
        '2001:1::1',
        '2001:4860:4860::8888',
    ] as $ip) {
        expect(sctPrivate('ipv6IsInternal', $ip))->toBeFalse($ip)
            ->and(PublicHttps::isPublicHttpsUrl('https://[' . $ip . ']/callback'))->toBeTrue($ip);
    }
});

test('ipv6 discard, benchmarking, orchid, documentation, srv6 and other nat64 addresses are internal', function () {
    // 100::/64 discard, 2001:2::/48 benchmarking, 2001:10::/28 ORCHID, 3fff::/20 documentation,
    // 5f00::/16 SRv6 SIDs, and 64:ff9b::/32 outside the well-known /96 are not public targets.
    foreach ([
        '100::',
        '100::a00:1',
        '100::ffff:ffff:ffff:ffff',
        '2001:2::',
        '2001:2::a00:1',
        '2001:2:0:ffff:ffff:ffff:ffff:ffff',
        '2001:10::',
        '2001:10::1',
        '2001:1f:ffff:ffff:ffff:ffff:ffff:ffff',
        '3fff::',
        '3fff::1',
        '3fff:fff:ffff:ffff:ffff:ffff:ffff:ffff',
        '5f00::',
        '5f00::1',
        '5f00:ffff:ffff:ffff:ffff:ffff:ffff:ffff',
        '64:ff9b::1:a00:1',
        '64:ff9b:2::1',
        '64:ff9b:ffff:ffff:ffff:ffff:ffff:ffff',
    ] as $ip) {
        expect(sctPrivate('ipv6IsInternal', $ip))->toBeTrue($ip)
            ->and(PublicHttps::isPublicHttpsUrl('https://[' . $ip . ']/callback'))->toBeFalse($ip);
    }
    // Neighbours just outside those ranges, the globally reachable AMT, AS112 and ORCHIDv2
    // assignments, and the NAT64 well-known prefix with a public IPv4 address stay public.
    foreach ([
        '100:0:0:1::',
        '2001:2:1::',
        '2001:f:ffff:ffff:ffff:ffff:ffff:ffff',
        '2001:3::1',
        '2001:4:112::1',
        '2001:20::1',
        '3ffe:ffff:ffff:ffff:ffff:ffff:ffff:ffff',
        '3fff:1000::',
        '5eff:ffff:ffff:ffff:ffff:ffff:ffff:ffff',
        '5f01::',
        '64:ff9b::808:808',
        '64:ff9c::',
    ] as $ip) {
        expect(sctPrivate('ipv6IsInternal', $ip))->toBeFalse($ip)
            ->and(PublicHttps::isPublicHttpsUrl('https://[' . $ip . ']/callback'))->toBeTrue($ip);
    }
});

test('url shape rules: https only, no credentials, bracketed ipv6, ports and case', function () {
    foreach ([
        'https://8.8.8.8/callback',
        'HTTPS://8.8.8.8/callback',
        'HtTpS://8.8.8.8/callback',
        '  https://8.8.8.8/callback  ',
        'https://8.8.8.8:8443/callback',
        'https://8.8.8.8./callback',
        'https://8.8.8.8',
        'https://8.8.8.8/callback?x=127.0.0.1#10.0.0.1',
        'https://[2606:4700:4700::1111]/callback',
        'https://[2606:4700:4700::1111]:443/callback',
    ] as $url) {
        expect(PublicHttps::isPublicHttpsUrl($url))->toBeTrue($url);
    }

    foreach ([
        '',
        '   ',
        'http://8.8.8.8/callback',
        'ftp://8.8.8.8/callback',
        'wss://8.8.8.8/callback',
        '//8.8.8.8/callback',
        '8.8.8.8/callback',
        'https:8.8.8.8/callback',
        'https:///callback',
        'https://user@8.8.8.8/callback',
        'https://user:secret@8.8.8.8/callback',
        'https://:secret@8.8.8.8/callback',
        'https://user:secret@[2606:4700:4700::1111]/callback',
        'https://2606:4700:4700::1111/callback',
        'https://[8.8.8.8]/callback',
        'https://[fe80::1%25eth0]/callback',
        'https://[::1]:443/callback',
        'https://[::1]./callback',
        'https://127.0.0.1:443/callback',
        'https://localhost:443/callback',
        'https://LOCALHOST/callback',
        'https://localhost./callback',
        'https://10.0.0.1./callback',
    ] as $url) {
        expect(PublicHttps::isPublicHttpsUrl($url))->toBeFalse($url);
    }
});

test('secret cipher round trips and a crypt payload is not legacy', function () {
    $cipher = new SecretCipher();

    foreach (['', 'a', 'secret with spaces', str_repeat('x', 1000), "multi\nline\0nul", 'ünïcödé'] as $plain) {
        $payload = $cipher->encrypt($plain);

        expect($payload)->not->toBe($plain)
            ->and($cipher->decrypt($payload))->toBe($plain)
            ->and($cipher->isLegacyCiphertext($payload))->toBeFalse()
            ->and($cipher->upgrade($payload))->toBe($payload)
            ->and($cipher->seal($payload))->toBe($payload);
    }

    $first  = $cipher->encrypt('same');
    $second = $cipher->encrypt('same');
    expect($first)->not->toBe($second)
        ->and($cipher->read($first))->toBe(['plain' => 'same', 'stored' => $first])
        ->and($cipher->reveal($second))->toBe('same');
});

test('legacy ciphertext is detected, decrypts, and upgrades through every entry point', function () {
    $cipher = new SecretCipher();
    $legacy = sctLegacyBlob('old-value');
    $empty  = sctLegacyBlob('');

    $upgraded = $cipher->upgrade($legacy);
    $sealed   = $cipher->seal($legacy);
    $opened   = $cipher->read($legacy);

    expect($cipher->isLegacyCiphertext($legacy))->toBeTrue()
        ->and($cipher->decrypt($legacy))->toBe('old-value')
        ->and($upgraded)->not->toBe($legacy)
        ->and($cipher->isLegacyCiphertext($upgraded))->toBeFalse()
        ->and($cipher->decrypt($upgraded))->toBe('old-value')
        ->and($sealed)->not->toBe($legacy)
        ->and($cipher->decrypt($sealed))->toBe('old-value')
        ->and($opened['plain'])->toBe('old-value')
        ->and($cipher->decrypt($opened['stored']))->toBe('old-value')
        ->and($cipher->reveal($legacy))->toBe('old-value')
        ->and($cipher->isLegacyCiphertext($empty))->toBeTrue()
        ->and($cipher->decrypt($empty))->toBe('');
});

test('legacy key derivation uses the raw app key when it is not base64 prefixed', function () {
    $cipher   = new SecretCipher();
    $original = (string) config('app.key');

    config()->set('app.key', 'plain-legacy-app-key');
    try {
        $legacy = sctLegacyBlob('plain-key-secret', 'plain-legacy-app-key');

        expect($cipher->isLegacyCiphertext($legacy))->toBeTrue()
            ->and($cipher->decrypt($legacy))->toBe('plain-key-secret');
    } finally {
        config()->set('app.key', $original);
    }
});

test('garbage input never decrypts: decrypt throws RuntimeException and read/reveal return null', function () {
    $cipher = new SecretCipher();

    $crypt       = $cipher->encrypt('real');
    $json        = json_decode(base64_decode($crypt), true);
    $json['mac'] = str_repeat('0', 64);
    $badMac      = base64_encode((string) json_encode($json));
    $json        = json_decode(base64_decode($crypt), true);
    $json['value'] .= 'AA';
    $badValue    = base64_encode((string) json_encode($json));

    $garbage = [
        'not-a-secret',
        'plain text with spaces',
        '!!!not base64!!!',
        base64_encode('short'),
        base64_encode(str_repeat("\0", 16)),
        base64_encode(random_bytes(16) . 'x'),
        base64_encode(random_bytes(16) . str_repeat('y', 15)),
        base64_encode('{"iv":"a","value":"b","mac":"c"}'),
        base64_encode('{"iv":1,"value":"b","mac":"c"}'),
        base64_encode('{"not":"crypt"}'),
        base64_encode('{'),
        $badMac,
        $badValue,
        substr($crypt, 0, -4),
    ];

    foreach ($garbage as $payload) {
        expect($cipher->isLegacyCiphertext($payload))->toBeFalse($payload)
            ->and(fn () => $cipher->decrypt($payload))->toThrow(RuntimeException::class, 'Unable to decrypt QuickBooks secret.')
            ->and($cipher->read($payload))->toBeNull($payload)
            ->and($cipher->reveal($payload))->toBeNull($payload)
            ->and($cipher->upgrade($payload))->toBe($payload);
    }

    expect(fn () => $cipher->decrypt(''))->toThrow(RuntimeException::class, 'Unable to decrypt QuickBooks secret.')
        ->and($cipher->isLegacyCiphertext(''))->toBeFalse()
        ->and($cipher->read(''))->toBe(['plain' => '', 'stored' => ''])
        ->and($cipher->reveal(''))->toBe('')
        ->and($cipher->seal(''))->toBe('')
        ->and($cipher->upgrade(''))->toBe('');
});

test('seal encrypts unrecognized values as plaintext and leaves crypt payloads alone', function () {
    $cipher = new SecretCipher();

    foreach (['plain-secret', base64_encode('short'), base64_encode(random_bytes(16) . 'x')] as $value) {
        $sealed = $cipher->seal($value);

        expect($sealed)->not->toBe($value)
            ->and($cipher->decrypt($sealed))->toBe($value)
            ->and($cipher->seal($sealed))->toBe($sealed);
    }

    // A crypt-shaped payload that fails its mac is kept as-is by seal, and unreadable.
    $fake = base64_encode('{"iv":"a","value":"b","mac":"c"}');
    expect($cipher->seal($fake))->toBe($fake)
        ->and($cipher->read($fake))->toBeNull();
});

test('a missing app key refuses every operation that touches key material', function () {
    $cipher   = new SecretCipher();
    $original = (string) config('app.key');
    $crypt    = $cipher->encrypt('kept');
    $legacy   = sctLegacyBlob('kept-legacy');

    config()->set('app.key', '');
    try {
        expect(fn () => $cipher->encrypt('x'))->toThrow(RuntimeException::class, 'APP_KEY must be set')
            ->and(fn () => $cipher->decrypt($crypt))->toThrow(RuntimeException::class, 'APP_KEY must be set')
            ->and(fn () => $cipher->decrypt($legacy))->toThrow(RuntimeException::class, 'APP_KEY must be set')
            ->and($cipher->read($crypt))->toBeNull()
            ->and($cipher->reveal($crypt))->toBeNull();
    } finally {
        config()->set('app.key', $original);
    }
});
