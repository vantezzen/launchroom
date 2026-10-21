<?php

declare(strict_types=1);

namespace App\Actions\Mesh;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Mesh\MeshCredentialPurpose;
use App\Enums\Mesh\MeshMode;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Services\Mesh\Exceptions\HeadscaleUnavailable;
use App\Services\Mesh\HeadscaleConnection;
use App\Services\Mesh\MeshSecrets;
use App\Services\Mesh\MeshTopology;

/**
 * Takes a revoked server out of the mesh (docs/09 R25): deletes its Headscale
 * node(s), found by the pre-auth keys issued to it and by the linked id
 * (never by a self-reported address, review M4), expires a pre-auth key it
 * never used and clears its mesh fields. With "bring your own Tailscale"
 * the node lives in the owner's tailnet: only the local fields are cleared
 * (the UI says to remove it there).
 *
 * @throws HeadscaleUnavailable (retried by the job and by MaintainMesh)
 */
final readonly class RemoveMeshNode
{
    public function __construct(
        private HeadscaleConnection $connection,
        private MeshSecrets $secrets,
        private MeshTopology $topology,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Server $server): void
    {
        $scope = MeshSecrets::serverScope($server);
        $pending = $this->secrets->find($scope, MeshCredentialPurpose::PreAuthKey);
        $nodeId = $server->mesh_node_id;

        if ($this->topology->mode(InstanceSettings::current()) === MeshMode::Embedded) {
            $client = $this->connection->client();

            // Every node registered with a key issued to this server (its
            // identity, review M4), plus the linked one.
            $deleted = [];

            foreach ($client->nodes() as $node) {
                if ($node->id === $nodeId || ($node->preAuthKeyId !== null && in_array($node->preAuthKeyId, $server->mesh_auth_key_ids, true))) {
                    $client->deleteNode($node->id);
                    $deleted[] = $node->id;
                }
            }

            if ($nodeId !== null && ! in_array($nodeId, $deleted, true)) {
                $client->deleteNode($nodeId);
            }

            $nodeId ??= $deleted[0] ?? null;

            if ($pending?->external_id !== null) {
                $client->expirePreAuthKey($pending->external_id);
            }
        }

        $this->secrets->forget($scope, MeshCredentialPurpose::PreAuthKey);
        $server->forceFill(['mesh_ip' => null, 'mesh_ipv6' => null, 'mesh_node_id' => null, 'mesh_auth_key_ids' => [], 'mesh_status' => null, 'mesh_status_at' => null])->save();

        if ($nodeId !== null) {
            $this->audit->handle(new AuditEntryData(
                action: AuditAction::MeshNodeRemoved,
                actor: AuditActor::system(),
                teamId: $server->team_id,
                subjectType: 'server',
                subjectId: $server->public_id,
                meta: ['node' => $nodeId],
            ));
        }
    }
}
