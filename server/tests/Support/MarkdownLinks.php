<?php

namespace Fleetbase\Quickbooks\Tests\Support;

/**
 * Finds links in a Markdown file that point nowhere: a relative file that does not exist, an
 * anchor with no matching heading, or a reference label with no definition. Web links are not
 * fetched, so the check stays offline. Raw HTML links, setext headings and shortcut references
 * such as [label] alone are not read; the docs use none of them.
 */
class MarkdownLinks
{
    /**
     * @return array<int, string> one line per broken link
     */
    public static function broken(string $file): array
    {
        $text        = self::withoutCode((string) file_get_contents($file));
        $definitions = self::definitions($text);
        $problems    = [];

        preg_match_all('/\[((?:[^\[\]]|\[[^\[\]]*\])*)\]\[([^\[\]]*)\]/', $text, $references, PREG_SET_ORDER);
        foreach ($references as $reference) {
            $label = self::label($reference[2] === '' ? $reference[1] : $reference[2]);
            if (array_key_exists($label, $definitions) === false) {
                $problems[] = 'no definition for [' . $label . ']';
            }
        }

        preg_match_all('/\[(?:[^\[\]]|\[[^\[\]]*\])*\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)/', $text, $inline);
        foreach (array_merge($inline[1], array_values($definitions)) as $target) {
            $problem = self::problem($file, $target);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * GitHub's anchors for the headings in a Markdown file.
     *
     * @return array<int, string>
     */
    public static function anchors(string $file): array
    {
        $source = self::withoutFences((string) file_get_contents($file));
        preg_match_all('/^ {0,3}#{1,6}[ \t]+(.+?)[ \t#]*$/m', $source, $headings);
        $anchors = [];
        $seen    = [];
        foreach ($headings[1] as $heading) {
            $text        = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $heading);
            $kept        = (string) preg_replace('/[^\p{L}\p{N}\p{Mn} _-]/u', '', mb_strtolower($text));
            $slug        = str_replace(' ', '-', $kept);
            $anchors[]   = isset($seen[$slug]) === true ? $slug . '-' . $seen[$slug] : $slug;
            $seen[$slug] = ($seen[$slug] ?? 0) + 1;
        }

        return $anchors;
    }

    private static function problem(string $file, string $target): ?string
    {
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $target) === 1) {
            return null;
        }

        [$path, $anchor] = array_pad(explode('#', $target, 2), 2, '');
        $resolved        = $path === '' ? $file : dirname($file) . '/' . rawurldecode($path);
        if (file_exists($resolved) === false) {
            return 'missing file ' . $target;
        }

        if ($anchor === '' || str_ends_with(strtolower($resolved), '.md') === false) {
            return null;
        }

        if (in_array(strtolower($anchor), self::anchors($resolved), true) === false) {
            return 'missing heading ' . $target;
        }

        return null;
    }

    /**
     * @return array<string, string> link target by lowercased label
     */
    private static function definitions(string $text): array
    {
        preg_match_all('/^ {0,3}\[([^\[\]]+)\]:[ \t]*<?([^\s>]+)>?/m', $text, $matches, PREG_SET_ORDER);
        $definitions = [];
        foreach ($matches as $match) {
            $definitions[self::label($match[1])] ??= $match[2];
        }

        return $definitions;
    }

    private static function label(string $label): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $label)));
    }

    /**
     * Code blocks and code spans can hold bracket text that is not a link.
     */
    private static function withoutCode(string $text): string
    {
        // A code span ends at a blank line, so a stray backtick cannot hide the rest of the file.
        return (string) preg_replace('/(`+)(?:(?!\1)[^\n]|\n(?![ \t]*\n))+?\1/', '', self::withoutFences($text));
    }

    private static function withoutFences(string $text): string
    {
        return (string) preg_replace('/^ {0,3}(```|~~~).*?^ {0,3}\1[^\n]*$/ms', '', $text);
    }
}
