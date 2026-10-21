<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Linux capabilities a service may add on top of cap-drop ALL: exactly the
 * default allow-list of launchroomd's policy floor (docs/03 §8). A contract
 * test compares the cases to the generated
 * protocol/schema/v1/capability-allow-list.json.
 */
enum WorkloadCapability: string
{
    case Chown = 'CHOWN';
    case DacOverride = 'DAC_OVERRIDE';
    case Fowner = 'FOWNER';
    case NetBindService = 'NET_BIND_SERVICE';
    case Setgid = 'SETGID';
    case Setuid = 'SETUID';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $capability): string => $capability->value, self::cases());
    }

    /** What granting it lets the service do, in plain words. */
    public function label(): string
    {
        return match ($this) {
            self::Chown => 'Change file owners',
            self::DacOverride => 'Read and write any file',
            self::Fowner => 'Change any file’s permissions',
            self::NetBindService => 'Listen on ports below 1024',
            self::Setgid => 'Switch to another group',
            self::Setuid => 'Switch to another user',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Chown => 'For images that hand their data folder to their own user when they start.',
            self::DacOverride => 'Ignores file permissions inside the container. Only for images that need it to start.',
            self::Fowner => 'Lets it set modes and timestamps on files it doesn’t own, such as a mounted volume.',
            self::NetBindService => 'For servers that listen on 80 or 443 inside the container, like nginx.',
            self::Setgid => 'For images that start as root and then drop to their own group.',
            self::Setuid => 'For images that start as root and then drop to their own user (su-exec, gosu).',
        };
    }
}
