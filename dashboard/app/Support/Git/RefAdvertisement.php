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
     * The branch HEAD points at, from the v0 capabilities
     * (`symref=HEAD:refs/heads/main`), else null.
     */
    public static function headSymref(string $body): ?string
    {
        foreach (self::lines($body) as $line) {
            $caps = explode("\0", $line, 2)[1] ?? null;

            if ($caps !== null && preg_match('#(?:^| )symref=HEAD:refs/heads/(\S+)#', $caps, $match) === 1 && GitRef::isValid('refs/heads/'.$match[1])) {
                return $match[1];
            }
        }

        return null;
    }

    /**
     * A protocol v2 `ls-refs` answer: `<sha> <ref>[ symref-target:<ref>]`
     * lines up to a flush packet.
     *
     * @return array{refs: array<string, string>, head: string|null}
     */
    public static function parseLsRefs(string $body): array
    {
        $refs = [];
        $head = null;

        foreach (self::lines($body) as $line) {
            $parts = explode(' ', rtrim($line, "\n"));

            if (count($parts) < 2 || ! GitRef::isSha($parts[0]) || ($parts[1] !== 'HEAD' && ! GitRef::isValid($parts[1]))) {
                continue;
            }

            $refs[$parts[1]] ??= $parts[0];

            foreach (array_slice($parts, 2) as $attribute) {
                if ($parts[1] === 'HEAD' && str_starts_with($attribute, 'symref-target:refs/heads/')) {
                    $target = substr($attribute, 25);
                    $head = GitRef::isValid('refs/heads/'.$target) ? $target : null;
                }
            }
        }

        return ['refs' => $refs, 'head' => $head];
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
