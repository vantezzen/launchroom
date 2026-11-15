<?php

declare(strict_types=1);

namespace App\Actions\Mesh;

use App\Data\Audit\AuditActor;
use App\Enums\Mesh\MeshMode;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Services\Mesh\Exceptions\HeadscaleUnavailable;
use App\Services\Mesh\MeshTopology;

/**
 * Switches the built-in mesh on by itself once two servers are approved
 * (docs/05 §2, `launchroom.mesh.auto_enable`), when no mesh is chosen yet.
 * Run when a server is approved (ProvisionMeshMemberJob) and by the
 * scheduled MaintainMesh, which catches up when that job gave up.
 */
final readonly class AutoEnableMesh
{
    public function __construct(
        private EnableMesh $enableMesh,
        private MeshTopology $topology,
    ) {}

    /**
     * @return bool whether it switched the mesh on
     *
     * @throws HeadscaleUnavailable (recorded on the instance by EnableMesh)
     */
    public function handle(): bool
    {
        if ($this->topology->mode(InstanceSettings::current()) instanceof MeshMode
            || ! config()->boolean('launchroom.mesh.auto_enable')
            || ! MeshTopology::embeddedAvailable()
            || Server::query()->meshMembers()->count() < 2) {
            return false;
        }

        $this->enableMesh->handle(AuditActor::system());

        return true;
    }
}
