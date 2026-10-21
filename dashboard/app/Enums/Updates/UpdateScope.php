<?php

declare(strict_types=1);

namespace App\Enums\Updates;

/**
 * What a self_update task updates (protocol UpdateScope, docs/11 §4).
 */
enum UpdateScope: string
{
    case Daemon = 'daemon';
    case ControlPlane = 'control_plane';
}
