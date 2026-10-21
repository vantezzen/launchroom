<?php

declare(strict_types=1);

namespace App\Enums\Mesh;

/**
 * How the servers of this instance are connected (docs/05 §2). No mode
 * (null) means the mesh is off: one server needs none.
 */
enum MeshMode: string
{
    /** Headscale in the control-plane stack; the dashboard manages keys and the ACL. */
    case Embedded = 'embedded';

    /** "Bring your own Tailscale": a tailnet's login server and auth key. */
    case OwnTailscale = 'byo';

    public function label(): string
    {
        return match ($this) {
            self::Embedded => 'Built-in mesh',
            self::OwnTailscale => 'Your Tailscale network',
        };
    }
}
