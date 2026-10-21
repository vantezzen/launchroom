<?php

declare(strict_types=1);

namespace App\Services\Ingress;

use App\Enums\IngressCredentialPurpose;
use App\Enums\Mesh\MeshMode;
use App\Models\Server;
use App\Services\Mesh\MeshTopology;
use App\Services\NodeStates\MeshLinks;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use SensitiveParameter;
use Throwable;

/**
 * Automatic shared certificate storage (docs/05 §5): with two or more edges
 * and the mesh on, every edge's Caddy stores certificates in the control
 * plane's Valkey. The control plane's launchroomd serves it on
 * `<its mesh IP>:6379` (NodeState `system.certStorage`) and its nftables let
 * only the edges' mesh IPs in. Edges log in as the dedicated ACL user
 * `lr-caddy`, limited to the storage's keys (`lr-caddy` is
 * caddy-storage-redis' top-level directory set, everything else lives under
 * `lr-caddy/`, locks under `lr-caddy/locks/`) and to the commands the module,
 * its lock library and launchroomd's mirror use. The password is generated
 * once and kept envelope-encrypted (`ingress_credentials`); edges get it
 * sealed. `LAUNCHROOM_CERT_STORAGE_ADDRESS` + password override all of this
 * (an external Redis).
 *
 * Valkey keeps ACL users only in memory: ensureUser() runs when a render of
 * the control plane needs it (at most once a minute) and every minute from
 * the scheduler (`launchroom:ingress:cert-storage-user`), so a Valkey restart
 * heals within a minute; edges serve their mirrored certificates meanwhile.
 */
final readonly class CertStorage
{
    public const string USERNAME = 'lr-caddy';

    public const string KEY_PREFIX = 'lr-caddy';

    public const int MESH_PORT = 6379;

    /**
     * Exactly the commands the edges' clients send (2026-10 review M3; no
     * categories, `@connection` would re-add `CLIENT KILL/PAUSE/LIST` on the
     * dashboard's main Valkey):
     *   - go-redis v9 handshake: `HELLO` (with credentials), `AUTH` fallback,
     *     `CLIENT SETINFO` (lib name/version; errors are ignored). No
     *     `SELECT` (db 0) and no `CLIENT SETNAME` (no client name rendered);
     *   - caddy-storage-redis v1.9.0: `PING GET SET DEL EXISTS ZADD ZREM
     *     ZRANGE SCAN TYPE`;
     *   - bsm/redislock v0.9.4: `EVALSHA`, `EVAL` on NOSCRIPT; inside the
     *     scripts (ACL-checked too) `GET SET GETRANGE PEXPIRE PTTL DEL`;
     *   - launchroomd's mirror (internal/ingress/certstore): `AUTH PING SCAN GET`.
     */
    public const string COMMANDS = '+hello +auth +client|setinfo +ping +get +set +del +exists +zadd +zrem +zrange +scan +type +evalsha +eval +getrange +pexpire +pttl';

    public function __construct(
        private IngressSecrets $secrets,
        private EdgeServers $edges,
        private MeshTopology $mesh,
    ) {}

    /**
     * The automatic storage while it applies: the control-plane server and
     * the mesh IPs of the other edges, else null.
     *
     * @return array{controlPlane: Server, address: string, edges: list<string>}|null
     */
    public function automatic(): ?array
    {
        $configured = config('launchroom.ingress.cert_storage.address');

        if ((is_string($configured) && $configured !== '') || ! config()->boolean('launchroom.ingress.caddy_modules') || ! $this->mesh->mode() instanceof MeshMode) {
            return null;
        }

        // Only edges an instance owner admitted (docs/05 §5).
        $edges = $this->edges->shared();

        if (count($edges) < 2) {
            return null;
        }

        $controlPlane = Server::query()->where('is_control_plane', true)->whereNull('revoked_at')->orderBy('id')->first();
        $ip = $controlPlane === null ? null : MeshLinks::meshIp($controlPlane->mesh_ip);

        if ($controlPlane === null || $ip === null) {
            return null;
        }

        $ips = [];

        foreach ($edges as $edge) {
            $edgeIp = MeshLinks::meshIp($edge->mesh_ip);

            if ($edge->id !== $controlPlane->id && $edgeIp !== null) {
                $ips[] = $edgeIp;
            }
        }

        $ips = array_values(array_unique($ips));
        sort($ips);

        return ['controlPlane' => $controlPlane, 'address' => $ip.':'.self::MESH_PORT, 'edges' => $ips];
    }

    /**
     * `system.certStorage.allowFrom` for $server: the edges' mesh IPs when it
     * is the control plane serving the automatic storage, else null (not served).
     *
     * @return list<string>|null
     */
    public function allowFrom(Server $server): ?array
    {
        $automatic = $server->is_control_plane ? $this->automatic() : null;

        if ($automatic === null || $automatic['controlPlane']->id !== $server->id) {
            return null;
        }

        try {
            $this->ensureUser(throttle: true);
        } catch (Throwable $e) {
            // The scheduled command retries; the edges keep their mirror meanwhile.
            Log::warning('Could not create the certificate storage ACL user on Valkey.', ['error' => $e::class]);
        }

        return $automatic['edges'];
    }

    /**
     * The storage the edges use: the configured one, else the automatic one.
     *
     * @return array{address: string, username: string, password: string, keyPrefix: string}|null
     */
    public function config(): ?array
    {
        $address = config('launchroom.ingress.cert_storage.address');

        if (is_string($address) && $address !== '') {
            $password = config('launchroom.ingress.cert_storage.password');
            $username = config('launchroom.ingress.cert_storage.username');
            $prefix = config('launchroom.ingress.cert_storage.key_prefix');

            return ['address' => $address, 'username' => is_string($username) ? $username : '', 'password' => is_string($password) ? $password : '', 'keyPrefix' => is_string($prefix) ? $prefix : ''];
        }

        $automatic = $this->automatic();

        if ($automatic === null) {
            return null;
        }

        return ['address' => $automatic['address'], 'username' => self::USERNAME, 'password' => $this->password(), 'keyPrefix' => self::KEY_PREFIX];
    }

    /**
     * Creates or resets the `lr-caddy` ACL user on the dashboard's Valkey
     * (idempotent). Only the password's SHA-256 travels in the command.
     */
    public function ensureUser(bool $throttle = false): void
    {
        if ($throttle && ! Cache::add('launchroom:cert-storage-user', true, 60)) {
            return;
        }

        Redis::connection()->command('acl', self::setUserArguments($this->password()));
    }

    /**
     * The `ACL SETUSER` arguments for $password (exposed for tests).
     *
     * @return list<string>
     */
    public static function setUserArguments(#[SensitiveParameter] string $password): array
    {
        return [
            'SETUSER', self::USERNAME, 'reset', 'on', '#'.hash('sha256', $password),
            '~'.self::KEY_PREFIX, '~'.self::KEY_PREFIX.'/*', 'resetchannels', '-@all',
            ...explode(' ', self::COMMANDS),
        ];
    }

    private function password(): string
    {
        return $this->secrets->ensure(IngressCredentialPurpose::StoragePassword, self::generatePassword(...));
    }

    /**
     * 43 URL-safe characters (256 bits).
     */
    public static function generatePassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
