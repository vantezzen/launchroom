<?php

declare(strict_types=1);

namespace App\Services\Mesh;

use App\Enums\ServerRole;
use App\Models\Server;
use App\Support\CanonicalJson;

/**
 * Mesh identities and the ACL policy (docs/05 §2). Pure: no I/O.
 *
 * Every server is a tagged node (no user owns it): `tag:lr-<role>` per role,
 * plus `tag:lr-cp` on the control-plane server. The policy is default deny;
 * it allows exactly:
 *
 *   - every launchroom server → the control plane on 443 (daemon API, ingress)
 *     and 5000 (registry, served by launchroomd on the control plane's mesh IP);
 *   - edge servers → the control plane on 6379 (shared certificate storage:
 *     its Valkey, served by launchroomd on its mesh IP, docs/05 §5);
 *   - edge servers → runtime servers on the mesh-published port range
 *     (31000–32999), where edges reach replicas on other servers;
 *   - runtime ↔ runtime on that range (ambassadors to databases on other
 *     servers);
 *   - every launchroom server → every launchroom server on 7400 (link
 *     probes: latency and throughput between servers, docs/05 §2.1).
 *
 * The receiving server's nftables narrow every one of these further:
 * published ports only from the servers hosting the same environment (and
 * edges for web services, `firewall.meshAllow`), 6379 only from the edges
 * (`system.certStorage.allowFrom`), 7400 only from the peers in
 * `system.linkProbe` (whose probes are also signed by their launchroomd).
 *
 * The mesh is transport, not trust (docs/09): these ports only carry
 * requests that are authenticated at the application layer.
 */
final class MeshPolicy
{
    public const string CONTROL_PLANE_TAG = 'tag:lr-cp';

    public const string PUBLISHED_PORTS = '31000-32999';

    /** Ports every server may reach on the control plane. */
    public const array CONTROL_PLANE_PORTS = [443, 5000];

    /** The shared certificate storage on the control plane (edges only). */
    public const int CERT_STORAGE_PORT = 6379;

    /** launchroomd's link probe listener on every server (docs/05 §2.1). */
    public const int LINK_PROBE_PORT = 7400;

    public static function roleTag(ServerRole $role): string
    {
        return 'tag:lr-'.$role->value;
    }

    /**
     * The tags of a server's mesh node (sorted, unique).
     *
     * @return list<string>
     */
    public static function tags(Server $server): array
    {
        $tags = [];

        foreach ($server->roles as $role) {
            $tags[] = self::roleTag($role);
        }

        if ($server->is_control_plane) {
            $tags[] = self::CONTROL_PLANE_TAG;
        }

        if ($tags === []) {
            // A server without roles still reaches the control plane.
            $tags[] = self::roleTag(ServerRole::Runtime);
        }

        $tags = array_values(array_unique($tags));
        sort($tags);

        return $tags;
    }

    /**
     * Every tag the policy knows (the tag owners).
     *
     * @return list<string>
     */
    public static function allTags(): array
    {
        $tags = array_map(self::roleTag(...), ServerRole::cases());
        $tags[] = self::CONTROL_PLANE_TAG;
        sort($tags);

        return $tags;
    }

    /**
     * The Headscale policy document (HuJSON-compatible JSON). Tags are owned
     * by the dashboard's Headscale user; pre-auth keys of that user carry them.
     *
     * @return array<string, mixed>
     */
    public static function document(string $owner): array
    {
        $all = self::allTags();
        $runtime = self::roleTag(ServerRole::Runtime);
        $owners = [];

        foreach ($all as $tag) {
            $owners[$tag] = [$owner.'@'];
        }

        return [
            'tagOwners' => $owners,
            'acls' => [
                [
                    'action' => 'accept',
                    'src' => $all,
                    'dst' => [self::CONTROL_PLANE_TAG.':'.implode(',', self::CONTROL_PLANE_PORTS)],
                ],
                [
                    'action' => 'accept',
                    'src' => [self::roleTag(ServerRole::Edge)],
                    'dst' => [self::CONTROL_PLANE_TAG.':'.self::CERT_STORAGE_PORT],
                ],
                [
                    'action' => 'accept',
                    'src' => [self::roleTag(ServerRole::Edge)],
                    'dst' => [$runtime.':'.self::PUBLISHED_PORTS],
                ],
                [
                    'action' => 'accept',
                    'src' => [$runtime],
                    'dst' => [$runtime.':'.self::PUBLISHED_PORTS],
                ],
                [
                    'action' => 'accept',
                    'src' => $all,
                    'dst' => array_map(static fn (string $tag): string => $tag.':'.self::LINK_PROBE_PORT, $all),
                ],
            ],
        ];
    }

    public static function render(string $owner): string
    {
        return CanonicalJson::encode(self::document($owner));
    }
}
