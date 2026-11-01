<?php

declare(strict_types=1);

namespace Fleetbase\Quickbooks\ReleaseCheck;

/**
 * What the release check expects to find in a Fleetbase install, read from this package's own
 * files so the lists never need updating by hand. Pure functions, unit tested in
 * server/tests/FleetbaseReleaseCheckTest.php.
 */
final class ReleaseChecks
{
    private const OWN_NAMESPACE = 'Fleetbase\\Quickbooks\\';

    /**
     * Fleetbase classes and events the extension names in a string, such as the models the
     * provider observes only when class_exists() is true. A rename upstream would turn that
     * part of the extension off without an error, so the check asks for each one.
     *
     * @return array<int, string> sorted, without this package's own classes
     */
    public static function externalClassNames(string $sourceDir): array
    {
        $names = [];
        foreach (self::phpFiles($sourceDir) as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all('/[\'"](Fleetbase(?:\\\\\\\\[A-Za-z][A-Za-z0-9_]*)+)[\'"]/', $source, $found);
            foreach ($found[1] as $literal) {
                $name = str_replace('\\\\', '\\', $literal);
                if (str_starts_with($name, self::OWN_NAMESPACE) === false) {
                    $names[$name] = true;
                }
            }
        }
        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /**
     * Routes the routes file declares with $router->get(...) or $router->post(...), comments left out.
     */
    public static function declaredRouteCount(string $routesPhp): int
    {
        $code = preg_replace('#^\s*//.*$#m', '', $routesPhp) ?? $routesPhp;

        return preg_match_all('/\$router->(?:get|post|put|patch|delete)\(/', $code);
    }

    /**
     * @return array<int, string> migration names as Laravel records them (file name without .php)
     */
    public static function migrationNames(string $migrationDir): array
    {
        $files = glob(rtrim($migrationDir, '/') . '/*.php');
        $names = array_map(
            static fn (string $file): string => basename($file, '.php'),
            $files === false ? [] : $files
        );
        sort($names);

        return $names;
    }

    /**
     * Permission names the extension's policies grant. fleetbase:create-permissions has to have
     * created each one, or the policies and roles grant nothing.
     *
     * @return array<int, string>
     */
    public static function policyPermissions(object $schema): array
    {
        $names = [];
        foreach ((array) ($schema->policies ?? []) as $policy) {
            foreach ((array) ($policy['permissions'] ?? []) as $permission) {
                $names[(string) $permission] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * @param array<int, string> $expected
     * @param array<int, string> $actual
     *
     * @return array<int, string> the expected items not in $actual, in their order
     */
    public static function missing(array $expected, array $actual): array
    {
        return array_values(array_diff($expected, $actual));
    }

    /**
     * @return array<int, string>
     */
    private static function phpFiles(string $dir): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        $files = [];
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
