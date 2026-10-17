<?php

namespace Fleetbase\Quickbooks\Tests\Support;

/**
 * Reads the docs and the package sources for DocumentationTest.
 */
class DocsSource
{
    public static function path(string $file = ''): string
    {
        return dirname(__DIR__, 3) . ($file === '' ? '' : '/' . $file);
    }

    public static function hasFiles(string $pattern): bool
    {
        return count(self::files($pattern)) > 0;
    }

    /**
     * @return array<int, string>
     */
    public static function files(string $pattern): array
    {
        $files = glob(self::path($pattern));

        return is_array($files) === true ? $files : [];
    }

    public static function text(string $file): string
    {
        return (string) file_get_contents(self::path($file));
    }

    /**
     * @param array<int, string> $needed
     *
     * @return array<int, string> the needed items the file does not mention
     */
    public static function missing(string $file, array $needed): array
    {
        return self::absentFrom(self::text($file), $needed);
    }

    /**
     * Compares with runs of whitespace collapsed, so a phrase split by a line wrap still counts.
     *
     * @param array<int, string> $needed
     *
     * @return array<int, string> the needed items the text does not mention
     */
    public static function absentFrom(string $text, array $needed): array
    {
        $flat   = static fn (string $value): string => (string) preg_replace('/\s+/', ' ', $value);
        $text   = $flat($text);
        $absent = fn (string $item): bool => str_contains($text, $flat($item)) === false;

        return array_values(array_filter(array_unique($needed), $absent));
    }

    /**
     * Reads server/config/quickbooks.php as text, because its env() calls need the host app.
     *
     * @return array<string, string> the source of each leaf value by dotted key
     */
    public static function configLeaves(): array
    {
        $leaves = [];
        $path   = [];
        foreach (explode("\n", self::text('server/config/quickbooks.php')) as $line) {
            if (preg_match('/^( +)\'([a-z_]+)\'\s*=>\s*(.*?),?\s*$/', $line, $match) !== 1) {
                continue;
            }
            $path = array_slice($path, 0, intdiv(strlen($match[1]), 4) - 1);
            if ($match[3] === '[') {
                $path[] = $match[2];
                continue;
            }
            $leaves[implode('.', array_merge($path, [$match[2]]))] = $match[3];
        }

        return $leaves;
    }

    /**
     * @return array<int, string> names read with env() or getenv() in server/, outside the tests,
     *                            and variables the docker/ scripts read without setting them
     */
    public static function environmentVariables(): array
    {
        $directory = new \RecursiveDirectoryIterator(self::path('server'), \FilesystemIterator::SKIP_DOTS);
        $files     = new \RecursiveIteratorIterator($directory);
        $names     = [];
        foreach ($files as $file) {
            if (str_contains($file->getPathname(), '/server/tests/') === true || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            preg_match_all('/\b(?:env|getenv)\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]/', $source, $matches);
            $names = array_merge($names, $matches[1]);
        }
        foreach (self::files('docker/*.sh') as $script) {
            $source = (string) file_get_contents($script);
            preg_match_all('/\$\{?([A-Z][A-Z0-9_]*)/', $source, $read);
            // A name the script sets is local, unless the value reads it too, as in X=${X:-1}.
            $assignment = '/^\s*(?:(?:export|local|readonly)\s+)?([A-Z][A-Z0-9_]*)=(.*)$/m';
            preg_match_all($assignment, $source, $set, PREG_SET_ORDER);
            $local = [];
            foreach ($set as [, $name, $value]) {
                if (preg_match('/\$\{?' . $name . '\b/', $value) !== 1) {
                    $local[] = $name;
                }
            }
            $names = array_merge($names, array_diff($read[1], $local));
        }

        return array_values($names);
    }
}
