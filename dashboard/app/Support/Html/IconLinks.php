<?php

declare(strict_types=1);

namespace App\Support\Html;

use Dom\Element;
use Dom\HTMLDocument;
use Throwable;

/**
 * The icons a page declares (`<link rel="icon">`, `shortcut icon`,
 * `apple-touch-icon`), best first, as absolute http(s) or data: URLs.
 * Parsed with the HTML5 parser; nothing in the page is executed or fetched.
 */
final class IconLinks
{
    private const int MAX_CANDIDATES = 8;

    /**
     * @return list<string>
     */
    public static function candidates(string $html, string $pageUrl): array
    {
        try {
            $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        } catch (Throwable) {
            return [];
        }

        $base = $pageUrl;
        $baseElement = $document->querySelector('base[href]');

        if ($baseElement instanceof Element) {
            $base = Urls::resolve($pageUrl, $baseElement->getAttribute('href') ?? '') ?? $pageUrl;
        }

        $scored = [];

        foreach ($document->querySelectorAll('link[rel][href]') as $index => $link) {
            if ($index > 200) {
                continue;
            }

            $rels = preg_split('/\s+/', strtolower(trim($link->getAttribute('rel') ?? ''))) ?: [];
            $touch = in_array('apple-touch-icon', $rels, true) || in_array('apple-touch-icon-precomposed', $rels, true);

            if (! $touch && ! in_array('icon', $rels, true)) {
                continue;
            }

            $url = Urls::resolve($base, trim($link->getAttribute('href') ?? ''));

            if ($url === null) {
                continue;
            }

            $score = self::score($url, strtolower($link->getAttribute('type') ?? ''), strtolower($link->getAttribute('sizes') ?? ''), $touch);
            $scored[$url] = max($scored[$url] ?? 0, $score);
        }

        arsort($scored);

        return array_slice(array_map(strval(...), array_keys($scored)), 0, self::MAX_CANDIDATES);
    }

    /**
     * Higher is better: a vector, else the raster closest to 128 px (an
     * apple-touch-icon is 180 px), else a declared icon of unknown size.
     */
    private static function score(string $url, string $type, string $sizes, bool $touch): int
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?: '');

        if ($type === 'image/svg+xml' || str_ends_with($path, '.svg') || str_starts_with($url, 'data:image/svg+xml')) {
            return 1000;
        }

        if ($sizes === 'any') {
            return 900;
        }

        $side = $touch ? 180 : 0;

        if (preg_match_all('/(\d+)x\d+/', $sizes, $matches) > 0) {
            $side = max(0, ...array_map(intval(...), $matches[1]));
        }

        if ($side === 0) {
            return str_ends_with($path, '.ico') ? 100 : 300;
        }

        return 800 - min(700, abs($side - 128) + ($side < 64 ? 300 : 0));
    }
}
