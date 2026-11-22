<?php

declare(strict_types=1);

// Prints THIRD-PARTY-LICENSES.md from composer.lock: php scripts/third-party-licenses.php > THIRD-PARTY-LICENSES.md
$root = dirname(__DIR__);
$lock = json_decode((string) file_get_contents($root . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$head = "| Package | Version | License |\n| --- | --- | --- |\n";

$table = static function (array $packages): string {
    usort($packages, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

    return implode("\n", array_map(
        static fn (array $p): string => '| ' . $p['name'] . ' | ' . ltrim($p['version'], 'v') . ' | '
            . implode(', ', $p['license'] ?? ['unspecified']) . ' |',
        $packages
    ));
};

echo <<<MD
# Third-party licenses

This extension is released under AGPL-3.0-or-later (see [LICENSE](LICENSE)).
It does not bundle the packages below. Composer installs them next to
Fleetbase, which ships its own copy of every package in the first list. Each
package keeps its own license. Use an SPDX identifier at
`https://spdx.org/licenses/<identifier>.html` for the full text.

The lists come from `composer.lock`, and each license is the one the package
declares to Composer. `webit/eval-math` declares none. Refresh them after every
`composer.lock` change with the command in
[CONTRIBUTING.md](CONTRIBUTING.md#third-party-licenses).

## Runtime packages

{$head}{$table($lock['packages'])}

## Development packages

These only run tests and linters. Fleetbase does not ship them.

{$head}{$table($lock['packages-dev'])}

MD;
