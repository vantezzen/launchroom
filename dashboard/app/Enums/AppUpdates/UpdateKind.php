<?php

declare(strict_types=1);

namespace App\Enums\AppUpdates;

/**
 * What an available release changes (docs/04 §14.1): the first number
 * (major), the second (minor), a later one (patch), or nothing but the image
 * behind the same tag (build).
 */
enum UpdateKind: string
{
    case Major = 'major';
    case Minor = 'minor';
    case Patch = 'patch';
    case Build = 'build';

    public function label(): string
    {
        return match ($this) {
            self::Major => 'Major update',
            self::Minor => 'Minor update',
            self::Patch => 'Patch update',
            self::Build => 'New build',
        };
    }

    /** Bigger first: major, minor, patch, build. */
    public function rank(): int
    {
        return match ($this) {
            self::Major => 3,
            self::Minor => 2,
            self::Patch => 1,
            self::Build => 0,
        };
    }
}
