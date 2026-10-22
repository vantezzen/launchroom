<?php

declare(strict_types=1);

namespace App\Http\Controllers\Servers;

use App\Actions\Servers\ApproveServer;
use App\Actions\Servers\DismissSmallServerHint;
use App\Actions\Servers\RevokeServer;
use App\Actions\Servers\UpdateServerRoles;
use App\Actions\Servers\UpdateServerSettings;
use App\Data\Ingress\EdgeIngressStatusData;
use App\Data\Servers\EnrollmentData;
use App\Data\Servers\ServerData;
use App\Data\Servers\ServerDetailData;
use App\Data\Servers\UpdateServerRolesData;
use App\Data\Servers\UpdateServerSettingsData;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Enums\ServerTab;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teams\TeamSettingsController;
use App\Models\Placement;
use App\Models\Server;
use App\Models\User;
use App\Queries\EdgeOverview;
use App\Queries\ServerHealth;
use App\Queries\ServerMesh;
use App\Queries\ServerRetiredVolumes;
use App\Queries\ServerRoleHints;
use App\Queries\ServerTopology;
use App\Queries\ServerWorkloads;
use App\Queries\SmallServerHint;
use App\Support\Servers\HostRoleLimits;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class ServerController extends Controller
{
    public function index(#[CurrentUser] User $user, ServerMesh $mesh, EdgeOverview $edges, ServerTopology $topology, SmallServerHint $smallServer): Response
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('viewAny', [Server::class, $team]);
        $controlPlaneIp = $mesh->controlPlaneIp();
        $servers = $team->servers()
            // Servers removed before they were ever approved never ran anything: not listed under "Revoked".
            ->where(static fn (Builder $query) => $query->where('status', '!=', ServerStatus::Revoked->value)->orWhereNotNull('approved_at'))
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
        $links = $topology->links($servers);

        $servers = $servers
            ->map(fn (Server $server): ServerData => ServerData::fromServer($server, $mesh->summary($server, $controlPlaneIp), is_numeric($workloads[$server->id] ?? null) ? (int) $workloads[$server->id] : 0))
            ->all();

        return Inertia::render('servers/index', [
            'servers' => $servers,
            'canAddServer' => Gate::allows('create', [Server::class, $team]),
            'mesh' => $mesh->overview($user),
            // Single point of failure badge and degraded certificate storage (docs/02 §5).
            'edgeOverview' => $edges->for($team->id),
            'canManageIngress' => Gate::allows('manageInstance'),
            'meshLinks' => $links,
            // "This server is small" (docs/08 §14).
            'smallServerHint' => $smallServer->for($user, $team),
            // Realtime metrics channel, waiting enrolments
            // and which unreachable servers can be replaced from their card.
            'teamId' => $team->public_id,
            'pendingEnrollments' => Gate::allows('create', [Server::class, $team])
                ? $team->serverEnrollments()
                    ->with('server')
                    ->whereNull('used_at')
                    ->where('expires_at', '>', now())
                    ->latest()
                    ->get()
                    ->map(EnrollmentData::fromEnrollment(...))
                    ->values()
                    ->all()
                : [],
            'replaceable' => $team->servers()
                ->where('is_control_plane', false)
                ->whereIn('status', [ServerStatus::Unreachable, ServerStatus::Offline])
                ->get()
                ->filter(fn (Server $server): bool => Gate::allows('replace', $server))
                ->map(fn (Server $server): string => $server->public_id)
                ->values()
                ->all(),
        ]);
    }

    public function show(Server $server, #[CurrentUser] User $user, ServerHealth $health, ServerRetiredVolumes $retiredVolumes, ServerMesh $mesh, ServerWorkloads $workloads, ServerRoleHints $roleHints, SmallServerHint $smallServer, ?ServerTab $tab = null): Response
    {
        Gate::authorize('view', $server);
        $tab ??= ServerTab::Overview;
        $overview = $tab === ServerTab::Overview;
        $settings = $tab === ServerTab::Settings;

        // Every tab shares the hero, the tab bar and the realtime channel;
        // the rest is only computed for the tab that shows it (docs/08 §14).
        $props = [
            'tab' => $tab,
            'server' => ServerDetailData::fromServer(
                $server,
                Gate::allows('approve', $server),
                Gate::allows('revoke', $server),
                // Device and location overrides (PATCH servers/{server}/appearance).
                Gate::allows('update', $server),
            ),
            // Mesh state and address (docs/05 §2): Overview's glance, Network.
            'mesh' => $mesh->summary($server),
            // How public traffic reaches this edge (docs/05 §4–5).
            'edge' => $server->roles->contains(ServerRole::Edge) ? EdgeIngressStatusData::fromServer($server) : null,
            // Realtime channel for the usage tiles and charts (servers.{team}).
            'teamId' => $server->team->public_id,
            // "Replace server" for a dead server, or who replaced it (docs/14 §5.3).
            'replace' => [
                'available' => Gate::allows('replace', $server) && ! $server->is_control_plane
                    && in_array($server->status, [ServerStatus::Unreachable, ServerStatus::Offline], true),
                'canView' => Gate::allows('replace', $server),
                'replacedBy' => $server->loadMissing('replacedBy')->replacedBy?->name,
                'replacedAt' => $server->replaced_at?->toIso8601String(),
            ],
        ];

        if ($overview) {
            $props['health'] = $health->evaluate($server);
        }

        if ($overview || $settings) {
            // Labels, preview/production flags, capacity and placed replicas (docs/02 §2).
            $props['placement'] = $workloads->for($server, Gate::allows('update', $server));
            // Contextual notes about the roles (docs/08 §14).
            $props['roleHints'] = $roleHints->forServer($server);
            // "This server is small" when this is the team's only, small build server (docs/08 §14).
            $hint = $smallServer->for($user, $server->team);
            $props['smallServerHint'] = $hint?->serverId === $server->public_id ? $hint : null;
            // Volumes of deleted environments/projects/previews (docs/05 §1.1).
            $props['retiredVolumes'] = $retiredVolumes->for($server);
        }

        if ($overview || $tab === ServerTab::Network) {
            // Peers with 24 h of measured link quality (docs/05 §2.1): the
            // heaviest query of the page, loaded after the first paint.
            $props['meshPeers'] = Inertia::defer(static fn (): array => $mesh->peers($server));
        }

        if ($tab === ServerTab::Network) {
            $props['measureLinks'] = $server->measure_links;
            // Docker mode, dockerd-guard and capabilities (D49); null until reported.
            $props['runtime'] = $server->runtime;
        }

        if ($settings) {
            // Edge, runtime and build can change after enrollment (docs/02 §2.2).
            $props['canUpdateRoles'] = Gate::allows('updateRoles', $server);
            // Roles this machine can't take, with the reason (a Mac never serves traffic, D53).
            $props['roleLimits'] = HostRoleLimits::limits($server);
            $props['canConfirmVolumeDeletion'] = Gate::allows('confirmVolumeDeletion', $server);
        }

        return Inertia::render('servers/show', $props);
    }

    /**
     * Dismisses "This server is small" for the current user (docs/08 §14).
     */
    public function dismissSmallServerHint(#[CurrentUser] User $user, DismissSmallServerHint $dismiss): RedirectResponse
    {
        Gate::authorize('create', [Server::class, TeamSettingsController::currentTeam($user)]);

        $dismiss->handle($user);

        return back();
    }

    public function updateSettings(Request $request, Server $server, UpdateServerSettingsData $data, #[CurrentUser] User $user, UpdateServerSettings $updateServerSettings): RedirectResponse
    {
        Gate::authorize('update', $server);

        $updateServerSettings->handle($server, $data, $this->auditActor($request, $user));

        return back();
    }

    public function updateRoles(Request $request, Server $server, UpdateServerRolesData $data, #[CurrentUser] User $user, UpdateServerRoles $updateServerRoles): RedirectResponse
    {
        Gate::authorize('updateRoles', $server);

        $updateServerRoles->handle($server, $data, $this->auditActor($request, $user));

        return back();
    }

    public function approve(Request $request, Server $server, #[CurrentUser] User $user, ApproveServer $approveServer): RedirectResponse
    {
        Gate::authorize('approve', $server);

        $approveServer->handle($server, $user, $this->auditActor($request, $user));

        return back();
    }

    public function revoke(Request $request, Server $server, #[CurrentUser] User $user, RevokeServer $revokeServer): RedirectResponse
    {
        Gate::authorize('revoke', $server);
        // A server removed before it was ever approved ran nothing: back to the fleet.
        $neverApproved = $server->status === ServerStatus::PendingApproval;

        $revokeServer->handle($server, $this->auditActor($request, $user));

        if ($neverApproved) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was removed. Its key no longer works.', ['name' => $server->name])]);

            return to_route('servers.index');
        }

        return back();
    }
}
