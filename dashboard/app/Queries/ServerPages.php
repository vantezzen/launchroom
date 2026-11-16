<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Ingress\EdgeIngressStatusData;
use App\Data\Servers\EnrollmentData;
use App\Data\Servers\ServerData;
use App\Data\Servers\ServerDetailData;
use App\Enums\ServerRole;
use App\Enums\ServerTab;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServerEnrollment;
use App\Models\Team;
use App\Models\User;
use App\Support\Servers\HostRoleLimits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Props of the Servers list and of a server's page (docs/08 §14).
 */
final readonly class ServerPages
{
    public function __construct(
        private ServerMesh $mesh,
        private EdgeOverview $edges,
        private ServerTopology $topology,
        private SmallServerHint $smallServer,
        private OpenAlerts $openAlerts,
        private ServerHealth $health,
        private ServerRetiredVolumes $retiredVolumes,
        private ServerWorkloads $workloads,
        private ServerRoleHints $roleHints,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function index(Team $team, User $user): array
    {
        $gate = Gate::forUser($user);
        $controlPlaneIp = $this->mesh->controlPlaneIp();
        $servers = $team->servers()
            // Servers removed before they were ever approved never ran anything: not listed under "Revoked".
            ->where(static fn (Builder $query) => $query->notRevoked()->orWhereNotNull('approved_at'))
            ->orderByDesc('is_control_plane')
            ->orderBy('name')
            ->get();
        $workloads = Placement::query()
            ->whereIn('server_id', $servers->modelKeys())
            ->groupBy('server_id')
            ->selectRaw('server_id, count(*) as workloads')
            ->pluck('workloads', 'server_id')
            ->all();

        // Private-network links between this team's servers (topology view).
        $links = $this->topology->links($servers);

        $alerts = $this->openAlerts->forServers($team->id, array_values(array_map(intval(...), $servers->modelKeys())));
        $servers = $servers
            ->map(fn (Server $server): ServerData => ServerData::fromServer($server, $this->mesh->summary($server, $controlPlaneIp), is_numeric($workloads[$server->id] ?? null) ? (int) $workloads[$server->id] : 0, $alerts[$server->id] ?? []))
            ->all();

        return [
            'servers' => $servers,
            'canAddServer' => $gate->allows('create', [Server::class, $team]),
            'mesh' => $this->mesh->overview($user),
            // Single point of failure badge and degraded certificate storage (docs/02 §5).
            'edgeOverview' => $this->edges->for($team->id),
            'canManageIngress' => $gate->allows('manageInstance'),
            'meshLinks' => $links,
            // "This server is small" (docs/08 §14).
            'smallServerHint' => $this->smallServer->for($user, $team),
            // Realtime metrics channel, waiting enrolments
            // and which unreachable servers can be replaced from their card.
            'teamId' => $team->public_id,
            'pendingEnrollments' => $gate->allows('create', [Server::class, $team])
                ? $team->serverEnrollments()
                    ->with('server')
                    ->whereNull('used_at')
                    ->where('expires_at', '>', now())
                    ->latest()
                    ->get()
                    ->map(static fn (ServerEnrollment $enrollment): EnrollmentData => EnrollmentData::fromEnrollment($enrollment))
                    ->values()
                    ->all()
                : [],
            'replaceable' => $team->servers()
                ->replaceable()
                ->get()
                ->filter(fn (Server $server): bool => $gate->allows('replace', $server))
                ->map(fn (Server $server): string => $server->public_id)
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(Server $server, User $user, ServerTab $tab): array
    {
        $gate = Gate::forUser($user);
        $overview = $tab === ServerTab::Overview;
        $settings = $tab === ServerTab::Settings;

        // Every tab shares the hero, the tab bar and the realtime channel;
        // the rest is only computed for the tab that shows it (docs/08 §14).
        $props = [
            'tab' => $tab,
            'server' => ServerDetailData::fromServer(
                $server,
                $gate->allows('approve', $server),
                $gate->allows('revoke', $server),
                // Device and location overrides (PATCH servers/{server}/appearance).
                $gate->allows('update', $server),
                $this->openAlerts->forServers($server->team_id, [$server->id])[$server->id] ?? [],
            ),
            // Mesh state and address (docs/05 §2): Overview's glance, Network.
            'mesh' => $this->mesh->summary($server),
            // How public traffic reaches this edge (docs/05 §4–5).
            'edge' => $server->roles->contains(ServerRole::Edge) ? EdgeIngressStatusData::fromServer($server) : null,
            // Realtime channel for the usage tiles and charts (servers.{team}).
            'teamId' => $server->team->public_id,
            // "Replace server" for a dead server, or who replaced it (docs/14 §5.3).
            'replace' => [
                'available' => $gate->allows('replace', $server) && $server->isReplaceable(),
                'canView' => $gate->allows('replace', $server),
                'replacedBy' => $server->loadMissing('replacedBy')->replacedBy?->name,
                'replacedAt' => $server->replaced_at?->toIso8601String(),
            ],
        ];

        if ($overview) {
            $props['health'] = $this->health->evaluate($server);
        }

        if ($overview || $settings) {
            // Labels, preview/production flags, capacity and placed replicas (docs/02 §2).
            $props['placement'] = $this->workloads->for($server, $gate->allows('update', $server));
            // Contextual notes about the roles (docs/08 §14).
            $props['roleHints'] = $this->roleHints->forServer($server);
            // "This server is small" when this is the team's only, small build server (docs/08 §14).
            $hint = $this->smallServer->for($user, $server->team);
            $props['smallServerHint'] = $hint?->serverId === $server->public_id ? $hint : null;
            // Volumes of deleted environments/projects/previews (docs/05 §1.1).
            $props['retiredVolumes'] = $this->retiredVolumes->for($server);
        }

        if ($overview || $tab === ServerTab::Network) {
            // Peers with 24 h of measured link quality (docs/05 §2.1): the
            // heaviest query of the page, loaded after the first paint.
            $props['meshPeers'] = Inertia::defer(fn (): array => $this->mesh->peers($server));
        }

        if ($tab === ServerTab::Network) {
            $props['measureLinks'] = $server->measure_links;
            // Docker mode, dockerd-guard and capabilities (D49); null until reported.
            $props['runtime'] = $server->runtime;
            $props['capabilities'] = $server->runtime?->rows() ?? [];
        }

        if ($settings) {
            // Edge, runtime and build can change after enrollment (docs/02 §2.2).
            $props['canUpdateRoles'] = $gate->allows('updateRoles', $server);
            // Roles this machine can't take, with the reason (a Mac never serves traffic, D53).
            $props['roleLimits'] = HostRoleLimits::limits($server);
            $props['canConfirmVolumeDeletion'] = $gate->allows('confirmVolumeDeletion', $server);
        }

        return $props;
    }
}
