<?php

declare(strict_types=1);

namespace App\Actions\Mesh;

use App\Enums\Mesh\MeshCredentialPurpose;
use App\Enums\Mesh\MeshMode;
use App\Events\NodeStateInvalidated;
use App\Models\InstanceSettings;
use App\Models\Mesh\MeshCredential;
use App\Models\Server;
use App\Queries\MeshServers;
use App\Services\Mesh\Exceptions\HeadscaleUnavailable;
use App\Services\Mesh\HeadscaleClient;
use App\Services\Mesh\HeadscaleConnection;
use App\Services\Mesh\HeadscaleNode;
use App\Services\Mesh\MeshPolicy;
use App\Services\Mesh\MeshSecrets;
use App\Services\Mesh\MeshTopology;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Keeps the built-in mesh converged (scheduled, and after a server joins):
 *
 * - rotates the dashboard's Headscale API key before it expires;
 * - re-applies the ACL policy when it differs from what this version renders
 *   or from what Headscale enforces;
 * - links each server to its Headscale node by identity: the node that
 *   registered with a pre-auth key issued to that server (never by the mesh
 *   address a server reports, review M4); the server's mesh addresses are
 *   the node's as Headscale reports them; a node claimed by two servers is
 *   linked to neither; keeps the node's tags equal to the server's roles;
 * - re-issues pre-auth keys of servers that have not joined while their key
 *   is about to expire;
 * - deletes the nodes of revoked servers (linked, or registered with one of
 *   their keys) a failed RemoveMeshNode left behind.
 *
 * Errors are recorded on the instance for the owner and never thrown.
 */
final readonly class MaintainMesh
{
    public function __construct(
        private HeadscaleConnection $connection,
        private IssueMeshAuthKey $issueKey,
        private MeshSecrets $secrets,
        private MeshTopology $topology,
    ) {}

    public function handle(): void
    {
        $settings = InstanceSettings::current();

        if ($this->topology->mode($settings) !== MeshMode::Embedded) {
            return;
        }

        try {
            $client = $this->connection->client();
            $this->connection->rotate();
            $client = $this->connection->client();

            $user = $settings->mesh_headscale_user ?? config()->string('launchroom.mesh.headscale_user');
            $policy = MeshPolicy::render($user);

            // Also when Headscale's live policy differs (e.g. a reset
            // Headscale database would otherwise allow everything until the
            // next change, review Info "ACL re-push").
            if ($settings->mesh_policy_hash === null || ! hash_equals($settings->mesh_policy_hash, hash('sha256', $policy)) || ! $this->livePolicyMatches($client, $policy)) {
                $client->setPolicy($policy);
                $settings->forceFill(['mesh_policy_hash' => hash('sha256', $policy)])->save();
            }

            $nodes = $client->nodes();
            $servers = MeshServers::query()->orderBy('id')->get();
            $this->adoptLinkedKeys($servers->all(), $nodes);
            $owners = $this->owners($servers->all(), $nodes);

            foreach ($servers as $server) {
                $this->maintainServer($server, $nodes, $owners, $client, $settings);
            }

            foreach (Server::query()->whereNotNull('revoked_at')->where(static fn (Builder $query) => $query->whereNotNull('mesh_node_id')->orWhereRaw("mesh_auth_key_ids <> '[]'::jsonb"))->get() as $revoked) {
                foreach ($nodes as $node) {
                    if ($node->id === $revoked->mesh_node_id || ($node->preAuthKeyId !== null && in_array($node->preAuthKeyId, $revoked->mesh_auth_key_ids, true))) {
                        $client->deleteNode($node->id);
                    }
                }

                $revoked->forceFill(['mesh_ip' => null, 'mesh_ipv6' => null, 'mesh_node_id' => null, 'mesh_auth_key_ids' => []])->save();
            }

            if ($settings->mesh_error !== null) {
                $settings->forceFill(['mesh_error' => null, 'mesh_error_at' => null])->save();
            }
        } catch (HeadscaleUnavailable $e) {
            $settings->forceFill(['mesh_error' => mb_substr($e->getMessage(), 0, 1000), 'mesh_error_at' => now()])->save();
        }
    }

    /**
     * Whether Headscale enforces $policy (compared as JSON); unknown (the
     * read failed) counts as matching, so a flaky read never loops pushes.
     */
    private function livePolicyMatches(HeadscaleClient $client, string $policy): bool
    {
        try {
            $live = $client->policy();
        } catch (HeadscaleUnavailable) {
            return true;
        }

        return json_decode($live, true) === json_decode($policy, true);
    }

    /**
     * Upgrade path: servers linked before key ids were recorded keep their
     * node, whose pre-auth key id becomes their identity (trust on first use
     * of the link made at the time; a key id another server holds is not
     * adopted).
     *
     * @param  array<int, Server>  $servers
     * @param  list<HeadscaleNode>  $nodes
     */
    private function adoptLinkedKeys(array $servers, array $nodes): void
    {
        $held = [];

        foreach ($servers as $server) {
            foreach ($server->mesh_auth_key_ids as $keyId) {
                $held[$keyId] = true;
            }
        }

        foreach ($servers as $server) {
            if ($server->mesh_auth_key_ids !== [] || $server->mesh_node_id === null) {
                continue;
            }

            foreach ($nodes as $node) {
                if ($node->id === $server->mesh_node_id && $node->preAuthKeyId !== null && ! isset($held[$node->preAuthKeyId])) {
                    $held[$node->preAuthKeyId] = true;
                    $server->forceFill(['mesh_auth_key_ids' => [$node->preAuthKeyId]])->save();
                }
            }
        }
    }

    /**
     * Which server each node belongs to, by the pre-auth key it registered
     * with (node id => server id); a key id two servers claim links no one.
     *
     * @param  array<int, Server>  $servers
     * @param  list<HeadscaleNode>  $nodes
     * @return array<string, int>
     */
    private function owners(array $servers, array $nodes): array
    {
        $byKey = [];

        foreach ($servers as $server) {
            foreach ($server->mesh_auth_key_ids as $keyId) {
                $byKey[$keyId] = isset($byKey[$keyId]) && $byKey[$keyId] !== $server->id ? 0 : $server->id;
            }
        }

        $owners = [];

        foreach ($nodes as $node) {
            $owner = $node->preAuthKeyId === null ? null : ($byKey[$node->preAuthKeyId] ?? null);

            if ($owner !== null && $owner !== 0) {
                $owners[$node->id] = $owner;
            }
        }

        return $owners;
    }

    /**
     * @param  list<HeadscaleNode>  $nodes
     * @param  array<string, int>  $owners
     */
    private function maintainServer(Server $server, array $nodes, array $owners, HeadscaleClient $client, InstanceSettings $settings): void
    {
        // The newest node registered with this server's keys (a re-joined
        // server leaves an older one behind until it expires).
        $node = null;

        foreach ($nodes as $candidate) {
            if (($owners[$candidate->id] ?? null) === $server->id && ($node === null || (int) $candidate->id > (int) $node->id)) {
                $node = $candidate;
            }
        }

        if ($node === null) {
            if ($server->mesh_ip !== null || $server->mesh_node_id !== null) {
                // Its node is gone (or was never linked by identity): the
                // server is off the mesh until it registers again.
                $server->forceFill(['mesh_ip' => null, 'mesh_ipv6' => null, 'mesh_node_id' => null])->save();
                event(new NodeStateInvalidated(array_values(array_unique([$server->id, ...MeshServers::ids()]))));
            }

            $pending = $this->secrets->find(MeshSecrets::serverScope($server), MeshCredentialPurpose::PreAuthKey);
            $refresh = now()->addMinutes(config()->integer('launchroom.mesh.pre_auth_key_refresh_minutes'));

            if (! $pending instanceof MeshCredential || $pending->expires_at === null || $pending->expires_at->isBefore($refresh)) {
                $this->issueKey->handle($client, $server, $settings);
            }

            return;
        }

        $ipv4 = null;
        $ipv6 = null;

        foreach ($node->ipAddresses as $ip) {
            if ($ipv4 === null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $ipv4 = $ip;
            } elseif ($ipv6 === null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                $ipv6 = $ip;
            }
        }

        $joined = $server->mesh_ip === null && $ipv4 !== null;
        $moved = $server->mesh_ip !== $ipv4;
        $server->forceFill(['mesh_node_id' => $node->id, 'mesh_ip' => $ipv4, 'mesh_ipv6' => $ipv6]);

        if ($server->isDirty()) {
            $server->save();
        }

        if ($joined) {
            $this->secrets->forget(MeshSecrets::serverScope($server), MeshCredentialPurpose::PreAuthKey);
        }

        if ($moved) {
            // Its own state (key dropped, published ports), the control
            // plane's registry address, and every mesh address other servers
            // render: ambassador upstreams, allow-lists, edge upstreams and
            // the certificate storage's allowed edges (docs/05 §3, §5).
            event(new NodeStateInvalidated(array_values(array_unique([$server->id, ...MeshServers::ids()]))));
        }

        $want = MeshPolicy::tags($server);
        $have = $node->tags;
        sort($have);

        if ($have !== $want) {
            $client->setTags($node->id, $want);
        }
    }
}
