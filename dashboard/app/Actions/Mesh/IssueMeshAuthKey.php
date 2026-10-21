<?php

declare(strict_types=1);

namespace App\Actions\Mesh;

use App\Enums\Mesh\MeshCredentialPurpose;
use App\Events\NodeStateInvalidated;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Services\Mesh\HeadscaleClient;
use App\Services\Mesh\MeshPolicy;
use App\Services\Mesh\MeshSecrets;

/**
 * Creates a single-use, tagged, short-lived Headscale pre-auth key for one
 * server and stores it envelope-encrypted until the server has joined
 * (docs/05 §2). The key reaches the server sealed in its NodeState
 * (MeshTopology), so the server's state is re-rendered. A previous key that
 * was never used is expired in Headscale.
 */
final readonly class IssueMeshAuthKey
{
    /** Pre-auth key ids remembered per server (a node registers with one of the latest). */
    public const int KEPT_KEY_IDS = 20;

    public function __construct(
        private MeshSecrets $secrets,
    ) {}

    public function handle(HeadscaleClient $client, Server $server, ?InstanceSettings $settings = null): void
    {
        $settings ??= InstanceSettings::current();
        $user = $settings->mesh_headscale_user ?? config()->string('launchroom.mesh.headscale_user');
        $scope = MeshSecrets::serverScope($server);
        $previous = $this->secrets->find($scope, MeshCredentialPurpose::PreAuthKey);

        $key = $client->createPreAuthKey(
            $client->ensureUser($user),
            MeshPolicy::tags($server),
            now()->addMinutes(config()->integer('launchroom.mesh.pre_auth_key_ttl_minutes')),
        );

        $this->secrets->put($scope, MeshCredentialPurpose::PreAuthKey, $key->key, $key->id, $key->expiresAt, $server);
        // The key id is the identity its Headscale node is linked by
        // (MaintainMesh), never the address the server reports (review M4).
        $server->forceFill(['mesh_auth_key_ids' => array_slice([...array_values(array_diff($server->mesh_auth_key_ids, [$key->id])), $key->id], -self::KEPT_KEY_IDS)])->save();

        if ($previous?->external_id !== null && $previous->external_id !== $key->id) {
            $client->expirePreAuthKey($previous->external_id);
        }

        event(new NodeStateInvalidated([$server->id]));
    }
}
