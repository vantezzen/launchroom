<?php

declare(strict_types=1);

namespace App\Support\Images;

use App\Enums\AppUpdates\UpdateKind;

/**
 * An image tag read as a version (docs/04 §14.1): `v6.2.0`, `6.71.0-alpine`,
 * `28.2.0-rootless`, `v0.64.2.8`, `2026.10.12-sha-3699278`. Tags are not
 * semver: they have one to four numbers, an optional `v` and a free suffix
 * after `-` that names a variant (`alpine`, `rootless`) or a build.
 *
 * Two tags are comparable only within one **channel**: the same prefix, the
 * same number of components and the same suffix family (the suffix with its
 * numbers and hashes left out, so `alpine3.20` and `alpine3.21` belong
 * together while `alpine` and `alpine3.21` don't).
 */
final readonly class ImageVersion
{
    private const string PATTERN = '/^(v|V)?(\d{1,9})((?:\.\d{1,9}){0,3})(?:-([A-Za-z0-9][A-Za-z0-9._-]{0,99}))?$/D';

    /** Words that mark a pre-release suffix (after numbers are stripped). */
    private const array PRERELEASE = ['alpha', 'beta', 'rc', 'pre', 'preview', 'dev', 'nightly', 'snapshot', 'canary', 'next', 'edge', 'test', 'experimental', 'unstable', 'insiders'];

    /**
     * @param  list<int>  $numbers
     */
    private function __construct(
        public string $tag,
        public string $prefix,
        public array $numbers,
        public string $suffix,
        public string $family,
        public bool $prerelease,
    ) {}

    public static function parse(string $tag): ?self
    {
        if (preg_match(self::PATTERN, $tag, $m) !== 1) {
            return null;
        }

        $numbers = [(int) $m[2]];

        foreach (array_filter(explode('.', $m[3]), static fn (string $part): bool => $part !== '') as $part) {
            $numbers[] = (int) $part;
        }

        $suffix = $m[4] ?? '';
        [$family, $prerelease] = self::family($suffix);

        return new self($tag, strtolower($m[1]), $numbers, $suffix, $family, $prerelease);
    }

    /**
     * The channel key: tags with the same key can be compared.
     */
    public function channel(): string
    {
        return $this->prefix.'|'.count($this->numbers).'|'.$this->family;
    }

    /**
     * -1, 0 or 1 by the numbers (then the suffix, so `1.0.0-alpine3.20` <
     * `1.0.0-alpine3.21`). Only meaningful within one channel.
     */
    public function compareTo(self $other): int
    {
        $byNumbers = $this->numbers <=> $other->numbers;

        return $byNumbers !== 0 ? $byNumbers : strnatcmp($this->suffix, $other->suffix) <=> 0;
    }

    public function isNewerThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    /**
     * What moving from $this to the newer $to is: the first number that
     * differs decides (a suffix-only change, such as a newer base image, is
     * a patch).
     */
    public function kindTo(self $to): UpdateKind
    {
        return match (true) {
            $to->numbers[0] !== $this->numbers[0] => UpdateKind::Major,
            count($this->numbers) > 1 && $to->numbers[1] !== $this->numbers[1] => UpdateKind::Minor,
            default => UpdateKind::Patch,
        };
    }

    /**
     * A tag with fewer than three numbers usually moves with its newest
     * release (`6`, `6.71`); it is still a version for the newer-major
     * comparison.
     */
    public function isShort(): bool
    {
        return count($this->numbers) < 3;
    }

    /**
     * The version without the variant, for people: `v6.2.0`, `6.71.0`.
     */
    public function display(): string
    {
        return ($this->prefix === '' ? '' : 'v').implode('.', $this->numbers);
    }

    /**
     * @return array{0: string, 1: bool} family and whether it is a pre-release
     */
    private static function family(string $suffix): array
    {
        if ($suffix === '') {
            return ['', false];
        }

        $tokens = [];
        $prerelease = false;

        foreach (preg_split('/[-._]/', strtolower($suffix)) ?: [] as $token) {
            if ($token === '') {
                continue;
            }

            if (ctype_digit($token) || preg_match('/^(?=.*\d)[0-9a-f]{7,}$/D', $token) === 1) {
                $token = '#';
            } else {
                $token = rtrim($token, '0123456789');
                $prerelease = $prerelease || in_array($token, self::PRERELEASE, true);
            }

            if ($token === '' || ($token === '#' && end($tokens) === '#')) {
                continue;
            }

            $tokens[] = $token;
        }

        return [implode('-', $tokens), $prerelease];
    }
}
