<?php

namespace Fleetbase\Quickbooks\Support;

use Fleetbase\Models\Setting;
use Symfony\Component\Yaml\Yaml;

/**
 * Server text comes from the same translations/{locale}.yaml files the console uses.
 * Text that is stored or thrown before anyone reads it holds an encoded key, and is
 * rendered in the reader's locale when it reaches them.
 */
class Translation
{
    public const FALLBACK_LOCALE = 'en-us';

    /** Invalid UTF-8 in a param is replaced rather than losing the whole message. */
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    /** Only these groups hold server messages; UI labels are never treated as stored text. */
    private const MESSAGE_GROUPS = ['quickbooks.messages.', 'quickbooks.validation.'];

    /** @var array<string, array<string, string>> */
    private static array $catalogs = [];

    /** @var array<int, string>|null */
    private static ?array $locales = null;

    /**
     * @param array<string, scalar|null> $params
     */
    public static function get(string $key, array $params = [], ?string $locale = null): string
    {
        $locale ??= self::currentLocale();
        $pattern = self::catalog($locale)[$key] ?? self::catalog(self::FALLBACK_LOCALE)[$key] ?? null;
        if ($pattern === null) {
            return $key;
        }

        return self::format($pattern, $params, $locale);
    }

    /**
     * A key with no params is stored as the bare key, otherwise as JSON.
     *
     * @param array<string, scalar|null> $params
     */
    public static function encode(string $key, array $params = []): string
    {
        if ($params === []) {
            return $key;
        }

        $encoded = json_encode(['key' => $key, 'params' => $params], self::JSON_FLAGS);

        return $encoded === false ? $key : $encoded;
    }

    /**
     * Two stored texts shown as one, separated by a space when rendered.
     */
    public static function append(string $existing, string $addition): string
    {
        $parts = [];
        foreach ([$existing, $addition] as $text) {
            $data  = str_starts_with($text, '{"parts":') === true ? json_decode($text, true) : null;
            $parts = array_merge($parts, is_array($data['parts'] ?? null) === true ? $data['parts'] : [$text]);
        }

        $encoded = json_encode(['parts' => array_values($parts)], self::JSON_FLAGS);

        return $encoded === false ? $existing . ' ' . $addition : $encoded;
    }

    /**
     * Render stored or thrown text. Text that is not an encoded key, such as an
     * older English row or Intuit's own fault detail, is returned unchanged.
     */
    public static function render(?string $stored, ?string $locale = null): ?string
    {
        if ($stored === null) {
            return null;
        }

        if (str_starts_with($stored, '{"parts":') === true) {
            $data = json_decode($stored, true);
            if (is_array($data['parts'] ?? null) === true) {
                $locale ??= self::currentLocale();
                $rendered = array_map(
                    static fn ($part): string => is_string($part) === true ? (string) self::render($part, $locale) : '',
                    $data['parts']
                );

                return implode(' ', array_filter($rendered, static fn (string $part): bool => $part !== ''));
            }
        }

        $decoded = self::decode($stored);
        if ($decoded === null) {
            return $stored;
        }

        return self::get($decoded['key'], $decoded['params'], $locale);
    }

    /**
     * True when the text is an encoded key that the catalog knows.
     */
    public static function isMessage(string $stored): bool
    {
        return self::decode($stored) !== null;
    }

    /**
     * The signed-in user's console locale, or en-us when there is none.
     */
    public static function currentLocale(): string
    {
        // With one translations file there is nothing to choose, so the setting is not read.
        if (self::locales() === [self::FALLBACK_LOCALE]) {
            return self::FALLBACK_LOCALE;
        }

        try {
            $user = function_exists('session') === true ? (string) session('user', '') : '';
            if ($user === '' || class_exists(Setting::class) === false) {
                return self::FALLBACK_LOCALE;
            }

            return self::normalize((string) Setting::lookup('user.' . $user . '.locale', self::FALLBACK_LOCALE));
        } catch (\Throwable) {
            return self::FALLBACK_LOCALE;
        }
    }

    /**
     * Only a locale that has a translations file is used. The value is user input,
     * so it is matched against the file list and never used to build a path.
     */
    public static function normalize(string $locale): string
    {
        $locale = strtolower(str_replace('_', '-', trim($locale)));

        return in_array($locale, self::locales(), true) === true ? $locale : self::FALLBACK_LOCALE;
    }

    /**
     * @return array{key: string, params: array<string, scalar|null>}|null
     */
    private static function decode(string $stored): ?array
    {
        $key    = $stored;
        $params = [];
        if (str_starts_with($stored, '{') === true) {
            $data  = json_decode($stored, true);
            $valid = is_array($data) === true
                && is_string($data['key'] ?? null) === true
                && is_array($data['params'] ?? null) === true;
            if ($valid === false) {
                return null;
            }
            $key    = $data['key'];
            $params = array_filter($data['params'], static fn ($value): bool => $value === null || is_scalar($value));
        }

        $grouped = array_filter(self::MESSAGE_GROUPS, static fn (string $group): bool => str_starts_with($key, $group));
        if ($grouped === [] || isset(self::catalog(self::FALLBACK_LOCALE)[$key]) === false) {
            return null;
        }

        return ['key' => $key, 'params' => $params];
    }

    /**
     * ICU formatting, as ember-intl does. Plain {name} replacement is the fallback
     * when ext-intl is missing or the pattern does not parse.
     *
     * @param array<string, scalar|null> $params
     */
    private static function format(string $pattern, array $params, string $locale): string
    {
        $params = array_map(static fn ($value): string => (string) $value, $params);
        if (class_exists(\MessageFormatter::class) === true) {
            $formatted = \MessageFormatter::formatMessage($locale, $pattern, $params);
            if ($formatted !== false) {
                return $formatted;
            }
        }

        $replace = [];
        foreach ($params as $name => $value) {
            $replace['{' . $name . '}'] = $value;
        }

        return strtr($pattern, $replace);
    }

    /**
     * @return array<string, string>
     */
    private static function catalog(string $locale): array
    {
        if (isset(self::$catalogs[$locale]) === true) {
            return self::$catalogs[$locale];
        }

        $catalog = [];
        if (in_array($locale, self::locales(), true) === true) {
            $parsed = Yaml::parseFile(self::directory() . '/' . $locale . '.yaml');
            self::flatten(is_array($parsed) === true ? $parsed : [], '', $catalog);
        }

        return self::$catalogs[$locale] = $catalog;
    }

    /**
     * @param array<mixed>          $tree
     * @param array<string, string> $catalog
     */
    private static function flatten(array $tree, string $prefix, array &$catalog): void
    {
        foreach ($tree as $name => $value) {
            $key = $prefix . $name;
            if (is_array($value) === true) {
                self::flatten($value, $key . '.', $catalog);
            } elseif (is_scalar($value) === true) {
                $catalog[$key] = (string) $value;
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private static function locales(): array
    {
        if (self::$locales === null) {
            $files         = glob(self::directory() . '/*.yaml') ?: [];
            self::$locales = array_map(static fn (string $file): string => basename($file, '.yaml'), $files);
        }

        return self::$locales;
    }

    private static function directory(): string
    {
        return dirname(__DIR__, 3) . '/translations';
    }
}
