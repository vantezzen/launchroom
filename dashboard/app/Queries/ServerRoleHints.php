<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Servers\DeviceData;
use App\Data\Servers\RoleHintData;
use App\Data\Servers\ServerSpecsData;
use App\Enums\DeviceKind;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\Team;
use App\Support\Servers\RoleHintContext;
use App\Support\Servers\RoleHints;
use Illuminate\Database\Eloquent\Collection;

/**
 * Builds the context for App\Support\Servers\RoleHints from the team's
 * fleet: the hints of one server (server page) and, for the add-server
 * wizard, the hints of every machine × role choice, so the page can switch
 * between them without a request. Callers authorize.
 */
final readonly class ServerRoleHints
{
    /** The wizard's machine tiles and the device each stands for. */
    private const array MACHINES = [
        'cloud' => DeviceKind::Cloud,
        'dedicated' => DeviceKind::Dedicated,
        'home' => DeviceKind::Minipc,
        'mac' => DeviceKind::Compact,
        'board' => DeviceKind::Sbc,
    ];

    /** The roles the wizard offers. */
    private const array WIZARD_ROLES = [ServerRole::Build, ServerRole::Edge, ServerRole::Runtime];

    /**
     * @return list<RoleHintData>
     */
    public function forServer(Server $server): array
    {
        if ($server->status === ServerStatus::Revoked) {
            return [];
        }

        $fleet = $this->fleetFacts($this->fleet($server->team, except: $server));
        $specs = ServerSpecsData::fromServer($server);

        return RoleHints::for(new RoleHintContext(
            device: DeviceData::fromServer($server)->kind,
            roles: array_values($server->roles->all()),
            existing: true,
            cpus: $specs->cpus,
            memoryMb: $specs->memoryMb,
            arch: RoleHintContext::normalizeArch($specs->arch),
            battery: $server->facts->hardware?->battery === true,
            otherServers: $fleet['otherServers'],
            otherEdges: $fleet['otherEdges'],
            otherRuntimeArchs: $fleet['otherRuntimeArchs'],
            otherBuildArchs: $fleet['otherBuildArchs'],
        ));
    }

    /**
     * Keyed "machine:roles", roles sorted by value and comma-joined
     * ("home:build,edge"). Every key is present, with [] when nothing applies.
     *
     * @return array<string, list<RoleHintData>>
     */
    public function forWizard(Team $team): array
    {
        $fleet = $this->fleetFacts($this->fleet($team));
        $hints = [];

        foreach (self::MACHINES as $machine => $device) {
            foreach ($this->subsets() as $roles) {
                $key = $machine.':'.implode(',', array_map(static fn (ServerRole $role): string => $role->value, $roles));
                $hints[$key] = RoleHints::for(new RoleHintContext(
                    device: $device,
                    roles: $roles,
                    // A small board is an ARM machine; the others could be either.
                    arch: $device === DeviceKind::Sbc ? 'arm64' : null,
                    otherServers: $fleet['otherServers'],
                    otherEdges: $fleet['otherEdges'],
                    otherRuntimeArchs: $fleet['otherRuntimeArchs'],
                    otherBuildArchs: $fleet['otherBuildArchs'],
                ));
            }
        }

        return $hints;
    }

    /**
     * @return Collection<int, Server>
     */
    private function fleet(Team $team, ?Server $except = null): Collection
    {
        return $team->servers()
            ->whereNotIn('status', [ServerStatus::Revoked, ServerStatus::PendingApproval])
            ->whereNotNull('approved_at')
            ->when($except instanceof Server, static fn ($query) => $query->whereKeyNot($except?->getKey()))
            ->get();
    }

    /**
     * @param  Collection<int, Server>  $others
     * @return array{otherServers: int, otherEdges: int, otherRuntimeArchs: list<string>, otherBuildArchs: list<string>}
     */
    private function fleetFacts(Collection $others): array
    {
        $archs = static fn (ServerRole $role): array => array_values(array_unique(array_filter(
            $others->filter(static fn (Server $server): bool => $server->hasRole($role))
                ->map(static fn (Server $server): ?string => RoleHintContext::normalizeArch($server->facts->arch))
                ->all(),
        )));

        return [
            'otherServers' => $others->count(),
            'otherEdges' => $others->filter(static fn (Server $server): bool => $server->hasRole(ServerRole::Edge))->count(),
            'otherRuntimeArchs' => $archs(ServerRole::Runtime),
            'otherBuildArchs' => $archs(ServerRole::Build),
        ];
    }

    /**
     * Every non-empty set of the wizard's roles, sorted by value.
     *
     * @return list<list<ServerRole>>
     */
    private function subsets(): array
    {
        $sets = [];
        $count = count(self::WIZARD_ROLES);

        for ($mask = 1; $mask < (1 << $count); $mask++) {
            $sets[] = array_values(array_filter(
                self::WIZARD_ROLES,
                static fn (int $i): bool => ($mask & (1 << $i)) !== 0,
                ARRAY_FILTER_USE_KEY,
            ));
        }

        return $sets;
    }
}
