<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a server looks like (docs/design/direction.md §7.1): one device
 * illustration each. Detected by App\Support\Servers\DeviceClassifier from
 * the hardware facts; the user can pick another one (`servers.device_override`).
 */
enum DeviceKind: string
{
    /** A VPS at a known provider. */
    case Cloud = 'cloud';

    /** A rack server (bare metal at a host or in a rack). */
    case Dedicated = 'dedicated';

    case Tower = 'tower';

    /** Intel NUC, Beelink, ThinkCentre Tiny, OptiPlex Micro … */
    case Minipc = 'minipc';

    /** Mac mini, Mac Studio. */
    case Compact = 'compact';

    /** Raspberry Pi and other single-board computers. */
    case Sbc = 'sbc';

    case Nas = 'nas';

    case Laptop = 'laptop';

    /** A local virtual machine (or a container) at an unknown provider. */
    case Vm = 'vm';

    /** No hardware facts yet. */
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Cloud => 'Cloud server',
            self::Dedicated => 'Dedicated server',
            self::Tower => 'Tower PC',
            self::Minipc => 'Mini PC',
            self::Compact => 'Compact desktop',
            self::Sbc => 'Single-board computer',
            self::Nas => 'NAS',
            self::Laptop => 'Laptop',
            self::Vm => 'Virtual machine',
            self::Unknown => 'Unknown device',
        };
    }
}
