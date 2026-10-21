<?php

declare(strict_types=1);

namespace App\Actions\Mesh;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Servers\UseOwnTailscaleData;
use App\Enums\AuditAction;
use App\Enums\Mesh\MeshCredentialPurpose;
use App\Enums\Mesh\MeshMode;
use App\Events\NodeStateInvalidated;
use App\Models\InstanceSettings;
use App\Queries\MeshServers;
use App\Services\Mesh\MeshSecrets;
use Illuminate\Support\Facades\DB;

/**
 * "Bring your own Tailscale" (docs/05 §2): servers join a tailnet the owner
 * runs (Tailscale's own coordination server or their own Headscale) with its
 * login server and an auth key, instead of the built-in mesh. The auth key is
 * stored envelope-encrypted and reaches servers sealed in their NodeState
 * until they have joined. The tailnet's ACL is the owner's; launchroomd's
 * firewall still only accepts the control-plane ports from the mesh.
 */
final readonly class UseOwnTailscale
{
    public function __construct(
        private MeshSecrets $secrets,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(UseOwnTailscaleData $data, AuditActor $actor): void
    {
        DB::transaction(function () use ($data, $actor): void {
            $settings = InstanceSettings::lockedForUpdate();
            $previous = $settings->mesh_login_server;

            $this->secrets->put(MeshSecrets::instanceScope(), MeshCredentialPurpose::TailscaleAuthKey, $data->authKey);

            $settings->forceFill([
                'mesh_mode' => MeshMode::OwnTailscale,
                'mesh_enabled_at' => $settings->mesh_enabled_at ?? now(),
                'mesh_login_server' => $data->loginServer,
                'mesh_error' => null,
                'mesh_error_at' => null,
            ])->save();

            if ($previous !== $data->loginServer) {
                // Addresses from another tailnet are meaningless now.
                MeshServers::query()->update(['mesh_ip' => null, 'mesh_ipv6' => null, 'mesh_node_id' => null]);
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::MeshOwnTailscaleConfigured,
                actor: $actor,
                meta: ['login_server' => $data->loginServer],
            ));

            event(new NodeStateInvalidated(MeshServers::ids()));
        });
    }
}
