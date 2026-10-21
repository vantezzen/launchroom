<?php

declare(strict_types=1);

namespace App\Enums\Mesh;

/**
 * How traffic between two servers flows (Report.host.mesh.peers[].connection).
 */
enum MeshPath: string
{
    /** Directly (hole punched or public address). */
    case Direct = 'direct';

    /** Relayed through a DERP server (always works, slower). */
    case Relay = 'relay';

    /** No recent traffic; the path is decided on first use. */
    case Idle = 'idle';
}
