<?php

declare(strict_types=1);

namespace App\Jobs\Mesh;

use App\Actions\Mesh\EnableMesh;
use App\Actions\Mesh\IssueMeshAuthKey;
use App\Data\Audit\AuditActor;
use App\Enums\Mesh\MeshMode;
use App\Events\NodeStateInvalidated;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Queries\MeshServers;
use App\Services\Mesh\HeadscaleConnection;
use App\Services\Mesh\MeshTopology;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A server was approved (enrolled without approval, or approved): bring it
 * into the mesh (docs/05 §2).
 *
 * - Mesh off: switch the built-in mesh on once two servers are approved
 *   (`launchroom.mesh.auto_enable`); EnableMesh issues every server's key.
 * - Built-in mesh on: issue this server's pre-auth key.
 * - "Bring your own Tailscale": its NodeState carries the tailnet key; re-render.
 *
 * Idempotent; retried with backoff while Headscale is unreachable (the
 * scheduled MaintainMesh also catches up).
 */
#[Backoff([10, 30, 60, 120])]
#[Timeout(120)]
#[Tries(5)]
final class ProvisionMeshMemberJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $serverId,
    ) {}

    public function handle(EnableMesh $enableMesh, IssueMeshAuthKey $issueKey, HeadscaleConnection $connection, MeshTopology $topology): void
    {
        $server = MeshServers::query()->whereKey($this->serverId)->first();

        if (! $server instanceof Server) {
            return;
        }

        $settings = InstanceSettings::current();

        switch ($topology->mode($settings)) {
            case null:
                if (config()->boolean('launchroom.mesh.auto_enable') && MeshTopology::embeddedAvailable() && MeshServers::query()->count() >= 2) {
                    $enableMesh->handle(AuditActor::system());
                }

                return;
            case MeshMode::Embedded:
                if ($server->mesh_ip === null) {
                    $issueKey->handle($connection->client(), $server, $settings);
                }

                return;
            case MeshMode::OwnTailscale:
                event(new NodeStateInvalidated([$server->id]));

                return;
        }
    }
}
