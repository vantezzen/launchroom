<?php

declare(strict_types=1);

namespace App\Support\Updates;

/**
 * The few lines worth reading before updating (docs/design/flows.md §15):
 * the release notes' section headings, or their first bullet points when
 * the notes have no sub-headings. Plain text only: markdown syntax, links
 * and HTML are stripped; the UI never renders release notes as markup.
 */
final class ReleaseHighlights
{
    public const int MAX = 5;

    /**
     * @return list<string>
     */
    public static function from(string $notes): array
    {
        $headings = [];
        $bullets = [];

        foreach (preg_split('/\R/u', $notes) ?: [] as $line) {
            $line = trim($line);

            if (preg_match('/^#{2,4}\s+(.+)$/u', $line, $match) === 1) {
                $headings[] = $match[1];
            } elseif (preg_match('/^[-*+]\s+(.+)$/u', $line, $match) === 1) {
                $bullets[] = $match[1];
            }
        }

        $generic = '/^(what\'?s changed|changes|changelog|new contributors|full changelog|release notes|assets|commits)\b/i';
        $headings = array_values(array_filter(array_map(self::plain(...), $headings), static fn (string $h): bool => $h !== '' && preg_match($generic, $h) !== 1));
        $source = count($headings) >= 2 ? $headings : array_map(self::plain(...), $bullets);

        return array_slice(array_values(array_filter($source, static fn (string $item): bool => $item !== '')), 0, self::MAX);
    }

    private static function plain(string $text): string
    {
        $text = strip_tags($text);
        // [label](url) → label; images dropped.
        $text = (string) preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $text);
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text);
        $text = (string) preg_replace('/(\*\*|__|`|~~)/u', '', $text);
        $text = (string) preg_replace('/\s+by @[\w-]+ in https?:\/\/\S+$/u', '', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = trim($text, " \t*_#:");

        return mb_strlen($text) > 120 ? rtrim(mb_substr($text, 0, 117)).'…' : $text;
    }
}
