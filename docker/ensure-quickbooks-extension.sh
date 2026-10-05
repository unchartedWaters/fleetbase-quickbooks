#!/bin/sh
# Install the mounted QuickBooks package into this container's Composer project.
# The published fleetbase/fleetbase-api image does not contain the package.
# Do not print the environment, api/.env, or other secrets.
set -eu

PACKAGE=/fleetbase/packages/quickbooks

if [ ! -d "$PACKAGE" ] || [ ! -f "$PACKAGE/composer.json" ]; then
    echo "error: QuickBooks package is missing at /fleetbase/packages/quickbooks" >&2
    exit 1
fi

cd /fleetbase/api
export COMPOSER_ALLOW_SUPERUSER=1

decision=$(php <<'PHP'
<?php
ini_set('display_errors', 'stderr');

$packageFile = '/fleetbase/packages/quickbooks/composer.json';
$json = json_decode((string) file_get_contents($packageFile), true);
if (!is_array($json)) {
    fwrite(STDERR, "error: QuickBooks composer.json is not valid JSON\n");
    exit(1);
}

$version = $json['version'] ?? '';
if (!is_string($version)) {
    $version = '';
}
$version = trim($version);
if ($version !== '' && preg_match('/^[0-9A-Za-z._+-]+$/', $version) !== 1) {
    fwrite(STDERR, "error: QuickBooks composer.json version is not a pinned version\n");
    exit(1);
}

$constraint = $version !== ''
    ? 'unchartedwaters/quickbooks-api:' . $version
    : 'unchartedwaters/quickbooks-api:*';

$mountedRequire = $json['require'] ?? [];
if (!is_array($mountedRequire)) {
    fwrite(STDERR, "error: QuickBooks composer.json require is not an object\n");
    exit(1);
}

$providerInstalled = false;
$packagesPhp = '/fleetbase/api/bootstrap/cache/packages.php';
if (is_file($packagesPhp)) {
    $cache = file_get_contents($packagesPhp);
    if (is_string($cache) && str_contains($cache, 'QuickbooksServiceProvider')) {
        $providerInstalled = true;
    }
}

$installed = quickbooks_installed_package();
$matches = false;
if (is_array($installed)) {
    $installedVersion = trim((string) ($installed['version'] ?? ''));
    $installedRequire = $installed['require'] ?? [];
    if (!is_array($installedRequire)) {
        $installedRequire = [];
    }
    $matches = quickbooks_versions_match($version, $installedVersion)
        && quickbooks_requires_match($mountedRequire, $installedRequire);
}

if ($providerInstalled && $matches) {
    echo 'skip';
    exit(0);
}

echo 'require ' . $constraint;
exit(0);

function quickbooks_installed_package(): ?array
{
    $installedJson = '/fleetbase/api/vendor/composer/installed.json';
    if (is_file($installedJson)) {
        $data = json_decode((string) file_get_contents($installedJson), true);
        $found = quickbooks_find_package($data);
        if ($found !== null) {
            return $found;
        }
    }

    $lock = '/fleetbase/api/composer.lock';
    if (!is_file($lock)) {
        return null;
    }

    $data = json_decode((string) file_get_contents($lock), true);
    if (!is_array($data)) {
        return null;
    }

    foreach (['packages', 'packages-dev'] as $key) {
        foreach ($data[$key] ?? [] as $package) {
            if (is_array($package) && ($package['name'] ?? '') === 'unchartedwaters/quickbooks-api') {
                return $package;
            }
        }
    }

    return null;
}

function quickbooks_find_package($data): ?array
{
    if (!is_array($data)) {
        return null;
    }

    $packages = [];
    if (isset($data['packages']) && is_array($data['packages'])) {
        $packages = $data['packages'];
    } elseif (function_exists('array_is_list') && array_is_list($data)) {
        $packages = $data;
    }

    foreach ($packages as $package) {
        if (is_array($package) && ($package['name'] ?? '') === 'unchartedwaters/quickbooks-api') {
            return $package;
        }
    }

    return null;
}

function quickbooks_versions_match(string $mounted, string $installed): bool
{
    if ($mounted === $installed) {
        return true;
    }

    $normalize = static function (string $version): string {
        $version = ltrim($version, 'vV');
        if (preg_match('/^\d+\.\d+\.\d+$/', $version) === 1) {
            return $version . '.0';
        }

        return $version;
    };

    return $normalize($mounted) === $normalize($installed);
}

function quickbooks_requires_match(array $mounted, array $installed): bool
{
    return quickbooks_canon_require($mounted) === quickbooks_canon_require($installed);
}

function quickbooks_canon_require(array $require): string
{
    $canonical = [];
    foreach ($require as $name => $constraint) {
        if (!is_string($name)) {
            continue;
        }
        if (is_string($constraint) || is_int($constraint) || is_float($constraint)) {
            $canonical[$name] = (string) $constraint;
            continue;
        }
        $canonical[$name] = json_encode($constraint);
    }
    ksort($canonical);

    return (string) json_encode($canonical);
}
PHP
) || exit 1

case "$decision" in
    skip)
        ;;
    require\ *)
        constraint=${decision#require }
        composer config repositories.quickbooks '{"type":"path","url":"../packages/quickbooks","options":{"symlink":true}}'
        composer require "$constraint" \
            --update-no-dev \
            --no-interaction \
            --prefer-dist \
            --optimize-autoloader
        ;;
    *)
        echo "error: QuickBooks install check returned an unexpected result" >&2
        exit 1
        ;;
esac

# application migrates. queue and scheduler set QUICKBOOKS_SKIP_MIGRATE=1.
if [ "${QUICKBOOKS_SKIP_MIGRATE:-0}" != "1" ]; then
    php artisan migrate --force
fi

exec docker-php-entrypoint "$@"
