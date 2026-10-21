<?php

declare(strict_types=1);

namespace App\Enums\Mesh;

/**
 * A server's mesh state as the UI shows it (docs/05 §2).
 */
enum MeshMemberState: string
{
    /** The mesh is off, or the server has not reported a mesh client yet. */
    case Off = 'off';

    /** Starting, or waiting for its key. */
    case Connecting = 'connecting';

    case Connected = 'connected';

    case Error = 'error';

    public static function fromReported(?string $state): self
    {
        return match ($state) {
            'connected' => self::Connected,
            'starting', 'needs_login' => self::Connecting,
            'error' => self::Error,
            default => self::Off,
        };
    }
}
