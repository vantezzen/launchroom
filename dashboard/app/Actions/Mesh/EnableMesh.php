<?php

declare(strict_types=1);

namespace App\Actions\Mesh;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Mesh\MeshMode;
use App\Events\NodeStateInvalidated;
use App\Exceptions\DomainRuleViolation;
use App\Models\InstanceSettings;
use App\Queries\MeshServers;
use App\Services\Mesh\Exceptions\HeadscaleUnavailable;
use App\Services\Mesh\HeadscaleConnection;
use App\Services\Mesh\MeshPolicy;
use App\Services\Mesh\MeshTopology;
use Illuminate\Support\Facades\DB;

/**
 * Switches the built-in mesh on (docs/05 §2): automatically when a second
 * server joins, or by the instance owner. Idempotent.
 *
 * 1. Connect to the embedded Headscale (bootstraps the dashboard's API key).
 * 2. Ensure the Headscale user launchroom registers servers under.
 * 3. Apply the default-deny ACL policy (policy mode `database`).
 * 4. Mark the mesh on (mode `embedded`).
 * 5. Issue a pre-auth key for every server that has not joined yet and
 *    re-render every server's NodeState (mesh client, registry forwarding).
 *
 * Headscale errors are recorded on the instance (shown to the owner) and
 * re-thrown; nothing is marked on before the policy is in place.
 */
final readonly class EnableMesh
{
    public function __construct(
        private HeadscaleConnection $connection,
        private IssueMeshAuthKey $issueKey,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(AuditActor $actor): void
    {
        if (! MeshTopology::embeddedAvailable()) {
            throw DomainRuleViolation::conflict('This install has no built-in mesh. Connect your own Tailscale network instead.');
        }

        $user = config()->string('launchroom.mesh.headscale_user');

        try {
            $client = $this->connection->client();
            $client->ensureUser($user);
            $policy = MeshPolicy::render($user);
            $client->setPolicy($policy);
        } catch (HeadscaleUnavailable $e) {
            $this->recordError($e->getMessage());

            throw $e;
        }

        $wasOn = DB::transaction(function () use ($user, $policy): bool {
            $settings = InstanceSettings::lockedForUpdate();
            $wasOn = $settings->mesh_enabled_at !== null && $settings->mesh_mode === MeshMode::Embedded;

            $settings->forceFill([
                'mesh_mode' => MeshMode::Embedded,
                'mesh_enabled_at' => $settings->mesh_enabled_at ?? now(),
                'mesh_headscale_user' => $user,
                'mesh_policy_hash' => hash('sha256', $policy),
                'mesh_error' => null,
                'mesh_error_at' => null,
            ])->save();

            return $wasOn;
        });

        $settings = InstanceSettings::current();

        try {
            foreach (MeshServers::query()->whereNull('mesh_ip')->orderBy('id')->get() as $server) {
                $this->issueKey->handle($client, $server, $settings);
            }
        } catch (HeadscaleUnavailable $e) {
            $this->recordError($e->getMessage());

            throw $e;
        } finally {
            event(new NodeStateInvalidated(MeshServers::ids()));
        }

        if (! $wasOn) {
            $this->audit->handle(new AuditEntryData(
                action: AuditAction::MeshEnabled,
                actor: $actor,
                meta: ['mode' => MeshMode::Embedded->value],
            ));
        }
    }

    private function recordError(string $message): void
    {
        InstanceSettings::current()->forceFill([
            'mesh_error' => mb_substr($message, 0, 1000),
            'mesh_error_at' => now(),
        ])->save();
    }
}
