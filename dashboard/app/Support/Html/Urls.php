<?php

declare(strict_types=1);

namespace App\Support\Html;

/**
 * Relative URL resolution (RFC 3986 §5.2) limited to what we may fetch:
 * absolute http(s) URLs and `data:image/...` URIs. Everything else is null.
 */
final class Urls
{
    public static function resolve(string $base, string $reference): ?string
    {
        if ($reference === '' || strlen($reference) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $reference) === 1) {
            return null;
        }

        if (preg_match('/^data:image\//i', $reference) === 1) {
            return $reference;
        }

        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $reference, $scheme) === 1) {
            return in_array(strtolower($scheme[1]), ['http', 'https'], true) ? self::withoutFragment($reference) : null;
        }

        $parts = parse_url($base);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $origin = strtolower($parts['scheme']).'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($reference, '//')) {
            return self::withoutFragment(strtolower($parts['scheme']).':'.$reference);
        }

        $reference = self::withoutFragment($reference);

        if ($reference === '') {
            return null;
        }

        $pieces = explode('?', $reference, 2);
        $path = $pieces[0];
        $query = $pieces[1] ?? null;

        if ($path === '') {
            $path = $parts['path'] ?? '/';
        } elseif (! str_starts_with($path, '/')) {
            $directory = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/') ?? '/';
            $path = $directory.$path;
        }

        return $origin.self::removeDotSegments($path).($query !== null ? '?'.$query : '');
    }

    private static function withoutFragment(string $url): string
    {
        return explode('#', $url, 2)[0];
    }

    private static function removeDotSegments(string $path): string
    {
        $out = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($out);
            } elseif ($segment !== '.') {
                $out[] = $segment;
            }
        }

        $result = implode('/', $out);

        return str_starts_with($result, '/') ? $result : '/'.$result;
    }
}
