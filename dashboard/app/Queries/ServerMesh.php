<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Servers\MeshOverviewData;
use App\Data\Servers\MeshPeerData;
use App\Data\Servers\ServerMeshData;
use App\Enums\Mesh\MeshMode;
use App\Enums\Mesh\MeshPath;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\User;
use App\Services\Mesh\MeshTopology;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * Mesh views for the servers pages (docs/05 §2, docs/08).
 */
final readonly class ServerMesh
{
    public function __construct(
        private MeshTopology $topology,
        private ServerLinkQuality $quality,
    ) {}

    public function overview(User $user): MeshOverviewData
    {
        $settings = InstanceSettings::current();
        $mode = $this->topology->mode($settings);
        $canManage = Gate::forUser($user)->allows('manageInstance');

        return new MeshOverviewData(
            enabled: $mode instanceof MeshMode,
            mode: $mode,
            builtInAvailable: MeshTopology::embeddedAvailable(),
            canManage: $canManage,
            loginServer: $canManage ? $settings->mesh_login_server : null,
            error: $canManage ? $settings->mesh_error : null,
            errorAt: $canManage ? $settings->mesh_error_at : null,
            enabledAt: $settings->mesh_enabled_at,
        );
    }

    public function controlPlaneIp(): ?string
    {
        $ip = Server::query()->where('is_control_plane', true)->whereNull('revoked_at')->whereNotNull('mesh_ip')->orderBy('id')->value('mesh_ip');

        return is_string($ip) ? $ip : null;
    }

    public function summary(Server $server, ?string $controlPlaneIp = null): ServerMeshData
    {
        return ServerMeshData::fromServer($server, $controlPlaneIp ?? $this->controlPlaneIp());
    }

    /**
     * The server's peers, named when they belong to the same team, with the
     * measured link quality to each (docs/05 §2.1).
     *
     * @return list<MeshPeerData>
     */
    public function peers(Server $server): array
    {
        $peers = $server->mesh_status['peers'] ?? [];

        if ($peers === []) {
            return [];
        }

        $known = Server::query()
            ->whereIn('mesh_ip', array_column($peers, 'ipv4'))
            ->whereNull('revoked_at')
            ->get(['id', 'public_id', 'team_id', 'name', 'mesh_ip', 'is_control_plane'])
            ->keyBy('mesh_ip');

        $quality = $this->quality->forServer($server);
        $out = [];

        foreach ($peers as $peer) {
            $match = $known->get($peer['ipv4']);
            $sameTeam = $match instanceof Server && $match->team_id === $server->team_id;

            $out[] = new MeshPeerData(
                ip: $peer['ipv4'],
                serverId: $sameTeam ? $match->public_id : null,
                name: $sameTeam ? $match->name : null,
                isControlPlane: $match instanceof Server && $match->is_control_plane,
                online: $peer['online'],
                path: MeshPath::tryFrom($peer['connection']) ?? MeshPath::Idle,
                relay: $peer['relay'],
                lastHandshake: $peer['lastHandshake'] !== null ? CarbonImmutable::parse($peer['lastHandshake']) : null,
                quality: $match instanceof Server ? ($quality[$match->id] ?? null) : null,
            );
        }

        return $out;
    }
}
