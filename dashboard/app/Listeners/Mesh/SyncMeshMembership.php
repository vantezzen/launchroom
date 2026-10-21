<?php

declare(strict_types=1);

namespace App\Listeners\Mesh;

use App\Enums\ServerStatus;
use App\Events\ServerStatusChanged;
use App\Jobs\Mesh\ProvisionMeshMemberJob;
use App\Jobs\Mesh\RemoveMeshNodeJob;

/**
 * Servers join the mesh when they are approved and leave it when they are
 * revoked (docs/05 §2, docs/09 R25). Liveness changes don't matter.
 */
final class SyncMeshMembership
{
    public function handle(ServerStatusChanged $event): void
    {
        if ($event->from === ServerStatus::PendingApproval && $event->to->isApproved()) {
            dispatch(new ProvisionMeshMemberJob($event->serverId));
        }

        if ($event->to === ServerStatus::Revoked) {
            dispatch(new RemoveMeshNodeJob($event->serverId));
        }
    }
}
