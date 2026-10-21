<?php

declare(strict_types=1);

namespace App\Support\Git;

/**
 * Git's pkt-line ref advertisement (smart HTTP `info/refs` or the start of
 * `git-upload-pack` over SSH): `<4 hex length><sha> <ref>[\0caps]\n` lines,
 * flush packets `0000` between sections.
 */
final class RefAdvertisement
{
    /**
     * `ref => sha` for every advertised ref with a valid SHA-1.
     *
     * @return array<string, string>
     */
    public static function parse(string $body): array
    {
        $refs = [];

        foreach (self::lines($body) as $line) {
            $parts = explode(' ', rtrim(explode("\0", $line, 2)[0], "\n"), 2);

            if (count($parts) === 2 && GitRef::isSha($parts[0]) && ! isset($refs[$parts[1]])) {
                $refs[$parts[1]] = $parts[0];
            }
        }

        return $refs;
    }

    /**
     * Whether $buffer is a complete advertisement: whole pkt-lines up to a
     * flush packet after at least one ref line (or an empty repository's
     * immediate flush).
     */
    public static function isComplete(string $buffer): bool
    {
        $offset = 0;
        $length = strlen($buffer);

        while ($offset + 4 <= $length) {
            $size = self::size(substr($buffer, $offset, 4));

            if ($size === null) {
                return true; // garbage: stop reading, parse() finds nothing
            }

            if ($size === 0) {
                return $offset + 4 === $length || self::isComplete(substr($buffer, $offset + 4));
            }

            if ($size < 4) {
                return true;
            }

            $offset += $size;
        }

        return false;
    }

    /**
     * @return list<string> payloads of the data packets
     */
    private static function lines(string $body): array
    {
        $lines = [];
        $offset = 0;
        $length = strlen($body);

        while ($offset + 4 <= $length) {
            $size = self::size(substr($body, $offset, 4));

            if ($size === null || ($size > 0 && $size < 4) || $offset + max($size, 4) > $length) {
                break;
            }

            if ($size === 0) {
                $offset += 4;

                continue;
            }

            $lines[] = substr($body, $offset + 4, $size - 4);
            $offset += $size;
        }

        return $lines;
    }

    private static function size(string $hex): ?int
    {
        return preg_match('/^[0-9a-f]{4}$/D', $hex) === 1 ? (int) hexdec($hex) : null;
    }
}
