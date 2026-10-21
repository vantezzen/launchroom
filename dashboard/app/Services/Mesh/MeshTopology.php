<?php

declare(strict_types=1);

namespace App\Services\Mesh;

use App\Data\Protocol\V1\TailscaleSpec;
use App\Enums\Mesh\MeshCredentialPurpose;
use App\Enums\Mesh\MeshMode;
use App\Models\InstanceSettings;
use App\Models\Mesh\MeshCredential;
use App\Models\Server;
use App\Models\ServerEnrollment;
use App\Models\User;
use App\Services\Crypto\Exceptions\DecryptionFailed;
use App\Services\Instance\DashboardOrigin;
use App\Support\Crypto\MeshKeySealer;
use Illuminate\Support\Facades\Log;

/**
 * The mesh part of a server's NodeState (docs/05 §2, §6):
 *
 * - `system.tailscale`: off until the mesh is on. Built-in mesh: the login
 *   server is the dashboard's public origin, tags come from the pre-auth key,
 *   which is sealed into the state only until the server has joined. "Bring
 *   your own Tailscale": the tailnet's login server, the server's tags
 *   advertised, the tailnet auth key until it has joined.
 * - `system.registry`: the control-plane server serves the registry on its
 *   mesh IP; every other server forwards 127.0.0.1:5000 there once that IP is
 *   known.
 */
final readonly class MeshTopology
{
    public function __construct(
        private MeshSecrets $secrets,
    ) {}

    public function mode(?InstanceSettings $settings = null): ?MeshMode
    {
        $settings ??= InstanceSettings::current();

        return $settings->mesh_enabled_at === null ? null : $settings->mesh_mode;
    }

    /**
     * Whether the embedded Headscale exists on this install.
     */
    public static function embeddedAvailable(): bool
    {
        return is_string(config('launchroom.mesh.headscale_url')) && config('launchroom.mesh.headscale_url') !== '';
    }

    /**
     * The URL servers log in to with the built-in mesh: the configured one,
     * else the dashboard's install-time origin (APP_URL, Headscale's
     * `server_url`). It deliberately does not follow Settings → Dashboard
     * domain (docs/05 §4.1): Tailscale stores the login server in every
     * client's prefs, and changing it means a forced re-login with a new
     * pre-auth key, i.e. new nodes and mesh addresses on every server.
     */
    public static function embeddedLoginServer(): string
    {
        $configured = config('launchroom.mesh.login_server');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        $url = parse_url(resolve(DashboardOrigin::class)->installUrl());
        $scheme = is_array($url) && isset($url['scheme']) ? $url['scheme'] : 'https';
        $host = is_array($url) && isset($url['host']) ? strtolower($url['host']) : 'localhost';
        $port = is_array($url) && isset($url['port']) ? ':'.$url['port'] : '';

        return "{$scheme}://{$host}{$port}";
    }

    public function tailscale(Server $server, ?InstanceSettings $settings = null): TailscaleSpec
    {
        $settings ??= InstanceSettings::current();
        $mode = $this->mode($settings);

        // A replaced (decommissioned) or revoked server leaves the mesh and
        // never gets a key (review L9).
        if (! $mode instanceof MeshMode || $server->replaced_by_server_id !== null || $server->revoked_at !== null) {
            return new TailscaleSpec(enabled: false);
        }

        $image = config()->string('launchroom.daemon.system_images.tailscale');

        if ($mode === MeshMode::Embedded) {
            $credential = $server->mesh_ip === null
                ? $this->secrets->find(MeshSecrets::serverScope($server), MeshCredentialPurpose::PreAuthKey)
                : null;

            [$sealed, $fingerprint] = $this->sealed($server, $credential);

            return new TailscaleSpec(
                enabled: true,
                image: $image,
                loginServer: self::embeddedLoginServer(),
                authKeySealed: $sealed,
                authKeyFingerprint: $fingerprint,
            );
        }

        // The owner's reusable tailnet key goes only to servers an instance
        // owner enrolled or approved (review L1): a team admin can't extract
        // it by enrolling a box of their own.
        $credential = $server->mesh_ip === null && self::trustedByInstanceOwner($server)
            ? $this->secrets->find(MeshSecrets::instanceScope(), MeshCredentialPurpose::TailscaleAuthKey)
            : null;

        [$sealed, $fingerprint] = $this->sealed($server, $credential);

        return new TailscaleSpec(
            enabled: true,
            image: $image,
            loginServer: $settings->mesh_login_server,
            tags: MeshPolicy::tags($server),
            authKeySealed: $sealed,
            authKeyFingerprint: $fingerprint,
        );
    }

    /**
     * Whether an instance owner admitted $server: the control plane, a
     * server an instance owner approved, or one enrolled with a token an
     * instance owner created.
     */
    public static function trustedByInstanceOwner(Server $server): bool
    {
        if ($server->is_control_plane) {
            return true;
        }

        $owners = User::query()->select('id')->where('is_instance_owner', true);

        return ($server->approved_by !== null && User::query()->whereKey($server->approved_by)->where('is_instance_owner', true)->exists())
            || ServerEnrollment::query()->where('server_id', $server->id)->whereIn('created_by', $owners)->exists();
    }

    /**
     * @return array{0: string|null, 1: bool} forwardTo, serveOnMesh
     */
    public function registry(Server $server, ?InstanceSettings $settings = null): array
    {
        if (! $this->mode($settings) instanceof MeshMode) {
            return [null, false];
        }

        if ($server->is_control_plane) {
            return [null, true];
        }

        $controlPlaneIp = Server::query()
            ->where('is_control_plane', true)
            ->whereNull('revoked_at')
            ->whereNotNull('mesh_ip')
            ->orderBy('id')
            ->value('mesh_ip');

        return is_string($controlPlaneIp) && filter_var($controlPlaneIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            ? [$controlPlaneIp.':'.config()->integer('launchroom.mesh.registry_port'), false]
            : [null, false];
    }

    /**
     * @return array{0: string|null, 1: string|null} sealed key, fingerprint
     */
    private function sealed(Server $server, ?MeshCredential $credential): array
    {
        if (! $credential instanceof MeshCredential) {
            return [null, null];
        }

        try {
            $key = $this->secrets->reveal($credential);
        } catch (DecryptionFailed) {
            Log::error('A mesh key could not be decrypted; the server gets no key.', ['server' => $server->public_id, 'credential' => $credential->id]);

            return [null, null];
        }

        return [
            MeshKeySealer::seal($server->sealing_public_key, $server->public_id, $key),
            // Changes whenever the key is replaced (new row content), never reveals it.
            hash('sha256', $credential->id.'|'.$credential->ciphertext),
        ];
    }
}
