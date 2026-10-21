<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Semantic versions (semver.org 2.0) as used by releases, daemons and the
 * dashboard (`1.2.3`, `v1.2.3`, `1.3.0-beta.1`). Same grammar as the
 * protocol's Semver scalar (daemon/internal/state/scalars.go).
 */
final readonly class Semver
{
    private const string PATTERN = '/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/D';

    /**
     * @param  list<string>  $prerelease
     */
    private function __construct(
        public int $major,
        public int $minor,
        public int $patch,
        public array $prerelease,
    ) {}

    public static function parse(string $version): ?self
    {
        if (strlen($version) > 64 || preg_match(self::PATTERN, $version, $m) !== 1) {
            return null;
        }

        $pre = ($m[4] ?? '') === '' ? [] : explode('.', $m[4]);

        return new self((int) $m[1], (int) $m[2], (int) $m[3], $pre);
    }

    public static function isValid(string $version): bool
    {
        return self::parse($version) instanceof self;
    }

    /**
     * -1, 0 or 1 like the spaceship operator; null when either side is not a version.
     */
    public static function compare(string $a, string $b): ?int
    {
        $x = self::parse($a);
        $y = self::parse($b);

        if (! $x instanceof self || ! $y instanceof self) {
            return null;
        }

        return $x->compareTo($y);
    }

    /**
     * True when $a is a valid version strictly newer than $b.
     */
    public static function newer(string $a, string $b): bool
    {
        return self::compare($a, $b) === 1;
    }

    /**
     * The version without a leading "v" and without build metadata.
     */
    public function normalized(): string
    {
        $core = "{$this->major}.{$this->minor}.{$this->patch}";

        return $this->prerelease === [] ? $core : $core.'-'.implode('.', $this->prerelease);
    }

    public function isPrerelease(): bool
    {
        return $this->prerelease !== [];
    }

    public function compareTo(self $other): int
    {
        foreach ([[$this->major, $other->major], [$this->minor, $other->minor], [$this->patch, $other->patch]] as [$a, $b]) {
            if ($a !== $b) {
                return $a <=> $b;
            }
        }

        // A version without prerelease ranks above one with.
        if ($this->prerelease === [] || $other->prerelease === []) {
            return ($this->prerelease === []) <=> ($other->prerelease === []);
        }

        $count = max(count($this->prerelease), count($other->prerelease));

        for ($i = 0; $i < $count; $i++) {
            $a = $this->prerelease[$i] ?? null;
            $b = $other->prerelease[$i] ?? null;

            if ($a === null || $b === null) {
                return $a === null ? -1 : 1;
            }

            if ($a === $b) {
                continue;
            }

            $aNumeric = ctype_digit($a);
            $bNumeric = ctype_digit($b);

            if ($aNumeric && $bNumeric) {
                return (int) $a <=> (int) $b;
            }

            if ($aNumeric !== $bNumeric) {
                return $aNumeric ? -1 : 1;
            }

            return strcmp($a, $b) <=> 0;
        }

        return 0;
    }
}
