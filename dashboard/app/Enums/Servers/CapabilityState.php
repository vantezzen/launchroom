<?php

declare(strict_types=1);

namespace App\Enums\Servers;

/**
 * How well a runtime capability works on a server (D49): fully, limited, or
 * not at all.
 */
enum CapabilityState: string
{
    case Full = 'full';
    case Degraded = 'degraded';
    case Unavailable = 'unavailable';
}
