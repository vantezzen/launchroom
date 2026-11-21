<?php

declare(strict_types=1);

namespace App\Enums\AppUpdates;

/**
 * Which updates launchroom installs by itself in a service's maintenance
 * window (docs/04 §14.3). Majors never: they can change settings and data.
 * Stored on `service_instances.auto_update`; never rename a value.
 */
enum AutoUpdateMode: string
{
    case Off = 'off';
    case Patch = 'patch';
    case Minor = 'minor';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::Patch => 'Patch updates',
            self::Minor => 'Patch and minor updates',
        };
    }

    public function allows(UpdateKind $kind): bool
    {
        return match ($this) {
            self::Off => false,
            self::Patch => in_array($kind, [UpdateKind::Patch, UpdateKind::Build], true),
            self::Minor => $kind !== UpdateKind::Major,
        };
    }
}
