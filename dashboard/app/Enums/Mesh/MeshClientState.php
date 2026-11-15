<?php

declare(strict_types=1);

namespace App\Enums\Mesh;

/**
 * The mesh client's state as launchroomd reports it (Report.host.mesh.state).
 */
enum MeshClientState: string
{
    case Starting = 'starting';
    case NeedsLogin = 'needs_login';
    case Connected = 'connected';
    case Error = 'error';

    public function member(): MeshMemberState
    {
        return match ($this) {
            self::Connected => MeshMemberState::Connected,
            self::Starting, self::NeedsLogin => MeshMemberState::Connecting,
            self::Error => MeshMemberState::Error,
        };
    }
}
